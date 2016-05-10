<?php

namespace App\Http\Controllers\Frontend;

use Input, Validator, Redirect, Config, Response, Cookie, App, Request, Datetime;
// use Illuminate\Http\Request;
use App\Http\Controllers\BaseController;
use Illuminate\Support\Str;
use App\Category;
use App\Post;
use App\Tag;
use App\Breadcrumbs;
// use Illuminate\Pagination\Paginator as Paginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator; dfgdfgfdgfdgd
use Core\Handlers\AdstextActionHandler as AdstextHandler;

class NewsController extends BaseController
{
	/**
	 * Returns all the news posts.
	 *
	 * @return View
	 */
	public function getCategory($catSlug)
	{
		/* check category slug */
		$provinceSlug = $this->data['tagHandler']->getListProvinceSlugs();
		
		if(!is_null($provinceSlug) && in_array($catSlug, $provinceSlug)) {
			$data = $this->getProvinces($catSlug);
			return view('provinces/province', $data);
		} else {
			return $this->getNormalCategory($catSlug);
		}        
	}

	public function getNormalCategory($catSlug)
	{
		$categoryHandler = $this->data['categoryHandler'];
        $curr_time = new Datetime;
        $last_week = $curr_time->modify('-'.Config::get('app.backdays', 1).' day');

        //get current page
        $this->data['page'] = $page = Input::get('page', 1);
        //get limit of page
        $limitPost = Config::get('settings.paging_posts', 20);

        //get category data
        $this->data['category'] = $this->data['parent_category'] = $category = $categoryHandler->getCategoryBySlug($catSlug);
		// Check if the news category exists
		if (is_null($category))
		{
			return abort(404);
		}
		// redirect 301 to new slug if redirect_status = 1.
		if($category->redirect_status) {
			return Redirect::to('/'.$category->new_slug, 301);
		}
		//get count post of category by category slug
        $this->data['totalPost'] = $totalPost = $categoryHandler->getCountPostsBySlug($catSlug);

		$breadcrum = new Breadcrumbs;		
		if($category->parent_id == 0) {
			$this->data['parent_category_selected'] = $category->slug;
			$this->data['child_category_selected'] = null;
			$breadcrum->addCrumb($category->name, Category::burl($category), $category->slug);
		} else {
			if(isset($category->parent_slug)) {
				$this->data['parent_category'] = $parentCategory = $categoryHandler->getCategories($category->parent_slug);
			} else {
				// trý?ng h?p có tr? v? category nhýng không có thu?c tính parent_slug 
				$newCategory = $categoryHandler->setCategoryBySlug($category->slug);
				if(isset($newCategory)) {
					$this->data['parent_category'] = $parentCategory = $categoryHandler->getCategories($newCategory->parent_slug);
				}
			}
			
			$this->data['parent_category_selected'] = $parentCategory->slug;
			$this->data['child_category_selected'] = $category->slug;
			$breadcrum->addCrumb($parentCategory->name, Category::burl($parentCategory),$parentCategory->slug);
			$breadcrum->addCrumb($category->name, Category::burl($category), $category->slug);
		}
		$breadcrum->setDivider('');

		$this->data['breadcrumbs'] = $breadcrum->render();
		// return subcat in video
		if($this->data['parent_category']->slug == 'video')
			return Response::make('',301)->header('location',(string)'videos/'.$category->slug);

        // Get featured posts from redis
        $this->data['featured_posts'] = array();
		if($category->parent_id == 0) {
            $this->data['featured_posts'] = $this->data['sidebarHandler']->getPostByPosition('category_'.$category->id);
		}

        //get total pages
        $totalPages = ceil($totalPost/$limitPost);

        //get total pages of redis (default get max: 500 posts).
        $totalPagesRedis = ceil(500/$limitPost);

        //get list posts
        $listPosts = array();
        if(is_numeric($page) && (int)$page > 0 ) {
            if($page < $totalPagesRedis || $totalPages == $totalPagesRedis) {
                $startPost = ($page -1)*$limitPost;
                $endPost = ($page*$limitPost)-1;
                $listPosts = $categoryHandler->getPostsByCategorySlug($catSlug,$startPost,$endPost);
            } else {
                $listPosts =  $categoryHandler->getPostOfPage($catSlug);
            }
        } else {
            $listPosts =  $categoryHandler->getPostsByCategorySlug($catSlug);
        }
    	//pagination post of page
        $this->data['posts'] = new Paginator($listPosts, $totalPost, $limitPost, null, ['path' => Request::url()]);
        return view('news/category', $this->data);
	}

	public function getTopicIndex($month = null, $year = null, $sort = null)
	{
		//m?c ð?nh khi load trang
		$tagHandler = $this->data['tagHandler'];
		$now_date = getdate();
		$this->data['now_month'] = $now_month = $now_date['mon'];
		$this->data['now_year'] = $now_year = $now_date['year'];
		//Tùy ch?n nãm, tháng
		if(!is_null($month))
			$curr_month = $month;
		else
			$curr_month = $now_month;
		
		if(!is_null($year))
			$curr_year = $year;
		else
			$curr_year = $now_year;

		$this->data['curr_month'] = $curr_month;
		$this->data['curr_year'] = $curr_year;

		$allowed = array('time', 'featured');
		$this->data['sort'] = $curr_sort = in_array($sort, $allowed) ? $sort : 'time';
		$dataTopic = $tagHandler->getTopicsInMonth($curr_month, $curr_year, $curr_sort);

		$this->data['tags'] = array();
		if(isset($dataTopic['topics']) && !empty($dataTopic['topics'])) {
			$this->data['tags'] = $dataTopic['topics'];
		}

		$this->data['total'] = $dataTopic['totalTopic'];//t?ng s? ki?n trong tháng
		$this->data['currentPage'] = $dataTopic['currentPage'];
		$this->data['lastPage'] = $dataTopic['lastPage'];
		$this->data['active_months'] = array();//m?ng ch?a các tháng có s? ki?n

		for($i = 1; $i < 13; $i++){
			$tmp = $tagHandler->getTopicsInMonth($i, $curr_year, $curr_sort);
			if(isset($tmp) && $tmp['totalTopic'] > 0) {
				$this->data['active_months'][] = $i;
			}
		}
		return view('news/topic-index', $this->data);
	}

	public function getTagIndex()
	{
		//get total tags (limit 500/page).
		$this->data['totalTags'] = $totalTags = $this->data['tagHandler']->getCounTags();
		$totalPagesRedis = 1;
		$this->data['totalPage'] = $totalPages = ceil($totalTags/500); 

		//get current page
		$this->data['page'] = $page = Input::get('page', 1); 
        if(is_numeric($page) && (int)$page > 0 ) {
            if($page == $totalPagesRedis) {
                $listTags = $this->data['tagHandler']->getListTags();
            } else {
                $listTags =  $this->data['tagHandler']->getListTagsOfPage();
            }
        } else {
            $listTags =  $this->data['tagHandler']->getListTags();
        }
        
        $this->data['tags'] = $tags =  new Paginator($listTags, $totalTags, 500, null, ['path' => Request::url()]);

		return view('news/tag-index', $this->data);
	}

	/**
	 * Returns all the news posts from tag.
	 *
	 * @return View
	 */
	public function getTag($tagSlug)
	{
		$tagHandler = $this->data['tagHandler'];
        //get tag data from redis
        $this->data['tag'] = $tag = $tagHandler->getTagDataBySlug($tagSlug);

        // Check if the news category exists
        if (is_null($tag))
        {
            // If we ended up in here, it means that a page or a news post
            // don't exist. So, this means that it is time for 404 error page.
            return abort(404);
            exit();
        }
        //get limit pots
        $limitPost = Config::get('settings.paging_posts', 20);
        //get current page
        $this->data['page'] = $page = Input::get('page', 1);
        //get count post of tag
        $this->data['totalPost'] = $totalPost = $tagHandler->getCountPostBySlug($tagSlug);

        //get total pages of redis (default get max: 50 posts).
        $totalPagesRedis = ceil(500/$limitPost);
        //get total pages
        $this->data['totalPage'] = $totalPages = ceil($totalPost/$limitPost);

        //get list posts
        $listPosts = array();
        if(is_numeric($page) && (int)$page > 0 ) {
            if($page < $totalPagesRedis || $totalPages == $totalPagesRedis ) {
                $startPost = ($page -1)*$limitPost;
                $endPost = ($page*$limitPost)-1;
                $listPosts = $tagHandler->getPostByTagSlug($tagSlug,$startPost,$endPost);
            } else {
                $listPosts =  $tagHandler->getPostOfPageTag($tagSlug);
            }
        } else {
            $listPosts =  $tagHandler->getPostsByCategorySlug($tagSlug);
        }
        //pagination post of page
        if(isset($listPosts) && !empty($listPosts))
        	$this->data['posts'] = new Paginator($listPosts, $totalPost, $limitPost, null, ['path' => Request::url()]);

		$view = $tag->type == 'flag' ? 'tag' : $tag->type;
		// Show the page
		return view('news/'.$view, $this->data);
	}

	public function getPrint($slug) {

		// echo $slug;
		$extStr = explode('-',$slug);

		$pId = array_pop($extStr);

		if(is_null($slug)) $slug = $catSlug;
		$extStr 	= explode('-',$slug);
		$pId 		= end($extStr);

		$postHandler = $this->data['postHandler'];
        $data 		 = $postHandler->getPostData($pId, false);

        $this->data['post'] = $post = isset($data->data) ? $data->data : NULL;
        
		// Check if the news post exists
		if (is_null($post) || empty($post) || $post == '{null}')
		{
			// If we ended up in here, it means that a page or a news post
			// don't exist. So, this means that it is time for 404 error page.
			return abort(404);
		}

		return view('news/print', $this->data);
	}

	public function getVideo($catSlug = 'video')
	{
		$categoryHandler = $this->data['categoryHandler'];
        $this->data['category'] = $this->data['parent_category'] = $category = $categoryHandler->getCategories($catSlug);

        if(is_null($category)) return abort(404);

		$this->data['parent_category_selected'] = $category->slug;
		$this->data['child_category_selected'] = null;

        $list_featured =  $categoryHandler->getPostsByCategorySlug($catSlug);

        $this->data['featured_videos']  = $featured_videos = json_decode(reset($list_featured));
        $getPostVideo = $this->data['postHandler']->getPostData($featured_videos->id, false);

     	$this->data['tags_featured'] = isset($getPostVideo->tags) ? $getPostVideo->tags : null;

     	if(Request::ajax())
			return view('videos/view-item', $this->data, true);
		else
			return view('videos/index', $this->data);
	}

	public function getCatVideo($catSlug)
	{
		$categoryHandler = $this->data['categoryHandler'];
		$this->data['page'] = $page = Input::get('page', 1);
        $limitPost = 12;

		$this->data['totalPost'] = $totalPost = $categoryHandler->getCountVideosPosts($catSlug);
        
        $this->data['category'] = $this->data['parent_category'] = $category = $categoryHandler->getVideosCategoryData($catSlug);

		if (is_null($category)) return abort(404);

		$this->data['parent_category_selected'] = 'video';
		$this->data['child_category_selected'] = $category->slug;

		//get total pages
        $totalPages = ceil($totalPost/$limitPost);

        //get total pages of redis (default get max: 500 posts).
        $totalPagesRedis = ceil(500/$limitPost);

        //get list posts
        $listPosts = array();
        if(is_numeric($page) && (int)$page > 0 ) {
            if($page < $totalPagesRedis || $totalPages == $totalPagesRedis) {
                $startPost = ($page -1)*$limitPost;
                $endPost = ($page*$limitPost)-1;
                $listPosts = $categoryHandler->getVideosPostBySlug($catSlug,$startPost,$endPost);
            } else {
                $listPosts =  $categoryHandler->getVideosPostOfPage($catSlug);
            }
        } else {
            $listPosts =  $categoryHandler->getVideosPostBySlug($catSlug);
        }

        if(isset($listPosts) && !empty($listPosts)) {
        	$this->data['first_lastest'] = $first_lastest =json_decode(reset($listPosts));
        	$getPostVideo = $this->data['postHandler']->getPostData($first_lastest->id, false);
	     	$this->data['tags_lastest'] = isset($getPostVideo->tags) ? $getPostVideo->tags : null;
	     	$this->data['lastest'] = new Paginator($listPosts, $totalPost, $limitPost, null, ['path' => Request::url()]);
        } else {
        	$this->data['lastest'] = NULL;
        }

		if(Request::ajax()) 
			return view('videos/multi-video', $this->data);
		else
			return view('videos/category', $this->data);

	}
	public function getViewVideo($catSlug = 'video', $slug = null)
	{
		$postHandler = $this->data['postHandler'];
		$categoryHandler = $this->data['categoryHandler'];
		$sidebarHandler = $this->data['sidebarHandler'];

		if(is_null($slug)) $slug = $catSlug;
		$extStr = explode('-',$slug);
		$pId = array_pop($extStr);
		if($pId && $pId > 0) $data = $postHandler->getPostData($pId, false);
		
		if(is_null($data) || !$data || $data == '{null}') return abort(404);

		$this->data['post'] = $post = $data->data;
		$this->data['post_tags'] = $post_tags = isset($data->tags) ? $data->tags : null;
        $this->data['tags'] = $data->tags;
        $this->data['parentcatid'] = (int)$data->parentcatid;
        $this->data['media'] = $data->media;
        $this->data['relateposts'] = isset($data->relateposts) ? $data->relateposts : null;
        $this->data['topics'] = $data->topics;
        $this->data['tags_array'] = array();
		$this->data['category'] = $this->data['parent_category'] = $category = $data->parentcate;
		/*Breadcrumbs*/
        $breadcrum = new Breadcrumbs;
		if($category->parent_id == 0) {
			$this->data['parent_category_selected'] = $category->slug;
			$this->data['child_category_selected'] = null;
			$breadcrum->addCrumb($category->name, Category::burl($category), $category->slug);
		} else {
			$parentCategory = $this->data['parent_category'];
			if($parentCategory->slug == 'video') {
				$breadcrum->addCrumb($parentCategory->name, Config::get('app.url').'/videos',$parentCategory->slug);
				$breadcrum->addCrumb($category->name, Config::get('app.url').'/videos/'.$category->slug, $category->slug);
			} else {
				$breadcrum->addCrumb($parentCategory->name, Category::burl($parentCategory),$parentCategory->slug);
				$breadcrum->addCrumb($category->name, Category::burl($category), $category->slug);
			}
			$this->data['parent_category_selected'] = $parentCategory->slug;
			$this->data['child_category_selected'] = $category->slug;
		}
		$breadcrum->addCrumb(Str::words($post->title,13), $post->slug, '');
		$breadcrum->setDivider('');
		$this->data['breadcrumbs'] = $breadcrum->render();
		/*END Breadcrumbs*/ 
		if($post->category_url != 'video') {
			// return true url post if not in video cat.
			return Response::make('',301)->header('location',Post::burl($post->category_id,$post->category_url,$post->id,$post->slug));
		}

		if(!is_null($this->data['post'])) {
			$ids[] = $this->data['post']->id;
		}
		$this->data['ids'] = $ids;
		// l?y slug c?a chuyên m?c video
		$videoCategory = $categoryHandler->getCategoryBySlug(Config::get('app.video_category_slug', 'video'));
		//get video populars
		$video_populars = $sidebarHandler->getPostByPosition('category_'.$videoCategory->id);
		// $video_populars = $sidebarHandler->getListPostPopular('videos_populars', 1, 'video', 10);
		if(!is_null($video_populars)) {
			$this->data['populars'] = $video_populars;
		} else {
			$this->data['populars'] = NULL;
		}
		
		//get video lastest
        $this->data['currPostId'] = $post->id;

        $videos_lastest = $postHandler->getHotPostData('new', $videoCategory->id, 1, 20, 0, 9);
		if(!is_null($videos_lastest) && isset($videos_lastest['posts']) && count($videos_lastest['posts'])) {
			$this->data['lastest'] = $videos_lastest['posts'];
		} else {
			$this->data['lastest'] = NULL;
		}

        // l?y bài ð?c nhi?u theo chuyên m?c chính
        $mostview_post = $postHandler->getHotPostData('view', $this->data['parentcatid'], 1, 10, 0, 6);
        // n?u chuyên m?c chính không có bài ð?c nhi?u th? l?y theo chuyên m?c cha
        if(is_null($mostview_post) || count($mostview_post) < 1) {
        	$mostview_post = $postHandler->getHotPostData('view', $this->data['parent_category']->id, 1, 10, 0, 6);
        }

		$this->data['mostview_post'] = $mostview_post;

		// // UPDATE count_view
		$this->data['postViewId'] = $postViewId = null;
        if(!Cookie::get('ses_last_views_news') || Cookie::get('ses_last_views_news') != 'post_'.$post->id) {
        	$postHandler->setPostHits($post->id);
            $this->data['postViewId'] = $postViewId = Cookie::get('ses_last_views_news');
        }

        //get sub category
        $subcats = $categoryHandler->getCategories('video:subcats');
        $listSubCat = array();
        if(isset($subcats)) {
        	foreach ($subcats as $key => $cat) {
        		$listSubCat[] = $categoryHandler->getVideosCategoryData($cat);
        	}
        }
        $this->data['subsCategory'] = $subsCategories = $listSubCat;

     	if(Request::ajax()) {
    		return Response::json(array('post' => $this->data['post'],
        								'media' => $this->data['media'],
        								'tags' => $this->data['post_tags'])
        								);
     	}
		else {
			return view('videos/view-video', $this->data)->withCookie($postViewId);
		}
	}
	/**
	 * View a news post.
	 *
	 * @param  string  $slug
	 * @return View
	 * @throws NotFoundHttpException
	 */
	public function getView($pCatSlug, $catSlug, $slug = null)
	{
		$redis = App::make('redis');

		if(is_null($slug)) $slug = $catSlug;
		$extStr 	= explode('-',$slug);
		$pId 		= end($extStr);
		if(!is_numeric($pId)) return abort(404);
		$slug_url 	= implode('-', $extStr);

		$postHandler = $this->data['postHandler'];
        $data 		 = $postHandler->getPostData($pId, false);
        if(is_null($data) || !$data || $data == '{null}') return abort(404);

        $this->data['post'] = $post = $data->data;
        /*Check right URL */
        $post_url = Config::get('app.url').'/'.$post->category_url.'/'.$post->slug.'-'.$post->id;

        if(!isset($data->category) || is_null($data->category) || !isset($data->topics)) {
        	$postHandler->deleteKeyPost($pId);
        	return Redirect::to($post_url,301);
        }

        $this->data['category'] = $this->data['parent_category'] = $category = $data->category;
        
        if(isset($data->parentcate->id))
        	$this->data['parent_category'] = $data->parentcate;

        
        if($post->category_url != $pCatSlug) {
        	return Redirect::to($post_url,301);
        }
        if($post->slug.'-'.$post->id != $catSlug) {
        	return Redirect::to($post_url,301);
        }
		//check real url
		if($pCatSlug != $category->slug) {
			return Response::make('',301)->header('location', Post::setUrl($post->category_url, $post->slug, $post->id, ''));
        }

        if($category->parent_id == 0) {
        	$this->data['parent_category_selected'] = $category->slug;
        	$this->data['child_category_selected'] = null;
        	$category_parent_id = $category->id;
        } else {
        	$parentCategory = $this->data['parent_category'];
        	$this->data['parent_category_selected'] = $parentCategory->slug;
			$this->data['child_category_selected'] = $category->slug;
			$category_parent_id = $parentCategory->id;
        }
        /*Ads Text*/
        $adstext = AdstextHandler::getInstance();
		$this->data['adstext'] = $adstext->getData($this->data['parent_category_selected'],$category_parent_id);
        /*Breadcrumbs*/
        $cacheKey = 'posts:'.$post->id.':breadcrum';
        if(!$redis->exists($cacheKey)) {
	        $breadcrum = new Breadcrumbs;
			if($category->parent_id == 0) {
				$breadcrum->addCrumb($category->name, Category::burl($category), $category->slug);
			} else {
				if($parentCategory->slug == 'video') {
					$breadcrum->addCrumb($parentCategory->name, Config::get('app.url').'/videos',$parentCategory->slug);
					$breadcrum->addCrumb($category->name, Config::get('app.url').'/videos/'.$category->slug, $category->slug);
				} else {
					$breadcrum->addCrumb($parentCategory->name, Category::burl($parentCategory),$parentCategory->slug);
					$breadcrum->addCrumb($category->name, Category::burl($category), $category->slug);
				}
			}
			$breadcrum->addCrumb(Str::words($post->title,13), $post->slug, '');
			$breadcrum->setDivider('');
			$breadcrumbsRender = $breadcrum->render();

			$redis->set($cacheKey, $breadcrumbsRender);
			$redis->expire($cacheKey, 10800); // lýu breadcrumb trong 3 ti?ng
            $redis->ttl($cacheKey);
        }
        $this->data['breadcrumbs'] 	= $redis->get($cacheKey);
		/*END Breadcrumbs*/

        $this->data['parentcatid'] 	= (int)$data->parentcatid;
        $this->data['media'] 		= isset($data->media) ? $data->media : null;
        // relate select post id
        $relatePostIds = isset($data->relatePostIds) ? $data->relatePostIds : array();
        $relatePostIdMerge = $relatePostIds;
        
        if($relatePostIds && count($relatePostIds)) {
        	$this->data['relateposts'] = $postHandler->getPostByIds($relatePostIds);
		}
        $relatePostIds2 = isset($data->relatePostIds2) ? $data->relatePostIds2 : array();
        
        if($relatePostIds2 && count($relatePostIds2)) {
        	$this->data['relateposts2'] = $postHandler->getPostByIds($relatePostIds2);
        	$relatePostIdMerge 	= array_unique(array_merge($relatePostIdMerge, $relatePostIds2));
		}

        $this->data['topics'] = $data->topics;

		$this->data['post_tags'] 	= $post_tags 	= isset($data->tags) ? $data->tags : null;
		$this->data['post_topic'] 	= $post_topic 	= count($data->topics) ? $data->topics[0] : null;
		
		if(!is_null($post_topic)) {
			$this->data['totalTopicPost'] = $this->data['tagHandler']->getCountPostBySlug($post_topic->slug);
		}

        $this->data['tags_array'] = array();
        if(count($post_tags)) {
        	foreach ($post_tags as $key => $pt) {
        		$this->data['tags_array'][] = $pt->name;
			}
		}
        // l?y bài ð?c nhi?u theo chuyên m?c chính
        $mostview_post = $postHandler->getHotPostData('view', $this->data['parentcatid'], 1, 10, 0, 6);
        // n?u chuyên m?c chính không có bài ð?c nhi?u th? l?y theo chuyên m?c cha
        if(is_null($mostview_post) || count($mostview_post) < 1) {
        	$mostview_post = $postHandler->getHotPostData('view', $this->data['parent_category']->id, 1, 10, 0, 6);
        }

		$this->data['mostview_post'] = $mostview_post;
        // get next post
        $this->data['next_posts'] = implode(',', $relatePostIdMerge);

        if(isset($post->post_form)) 
        { 
        	if($post->post_form == 'picture') {
        		$this->data['picture_list'] = $postHandler->getListPicturePost($post->id);
        	} elseif ($post->post_form == 'live') {
        		$this->data['live_blocks'] = $postHandler->getLiveBlocksData($post->id);
        	}
        	
        }
		if(Request::ajax()) {
        	return Response::json(array('post' => $this->data['post'],
        								'media' => $this->data['media'],
        								'tags' => $this->data['post_tags'])
        								);
        } elseif((isset($data->media->mtype) && $data->media->mtype == 'video') || (isset($post->post_form) && $post->post_form == 'video')) {
        	return view('videos/view-video', $this->data);
        } else {
        	$viewForm = 'post';
        	if(isset($post->post_form) && $post->post_form != 'normal') {
        		$viewForm = $post->post_form;
        	}
        	$this->data['currPostId'] = $post->id;
        	return view('news/view-'.$viewForm, $this->data);
        }

	}

	/**
	 * @return json
	 * Code by Dinh.Bang
	 * Description: get new and inactive liveblocks then return json data
	 */
	public function getNewLiveBlocks() {
		$pid = Input::get('pid');
		$ids = (array)Input::get('ids');
		$order = e(Input::get('order'));
		$post = $this->data['postHandler']->getPostData($pid, false);
		$status = $post->data->is_live;
		// dd($post->data->is_live);

		$data = $this->data['postHandler']->getLiveBlocksData($pid, $order);

		if(!is_null($data) && count($data)) {
			foreach ($data as $key => $value) {
				$value = json_decode($value);
				if(!is_null($ids) && count($ids)) {
					foreach ($ids as $k => $v) {
						
						if($value->block_id == $v) {
							unset($data[$key], $ids[$k]);
							break;
						}
					}
				}
			}
		}
		/* sau khi ch?y xong v?ng l?p th?:
		 * 		$ids : ch?a các m?u tin tý?ng thu?t ð? inactive
		 * 		$data: ch?a các m?u tin tý?ng thu?t m?i thêm vào
		 */
		return Response::json(array('new_block' => $data, 'inactive_block' => $ids, 'status' => $status));
	}


	public function updatePostView($postId) {
		// UPDATE count_view
		$this->data['postHandler']->setPostHits($postId);
	}



	public function getAjaxLastestPosts() {

		$limit = e(Input::get('limit', 6));

		$this->data['posts'] = $posts = Post::select('posts.*', 'medias.mpath', 'medias.mtype', 'medias.mname')
			->leftJoin('medias', 'medias.id', '=', 'posts.media_id')
			->where('status', 'published')
			->where('post_type', 'post')
			->where('posts.publish_date', '<=', new Datetime())
			->orderBy('publish_date', 'DESC')->take($limit)->get();

		return view('widgets/mostpopulars/ajaxlist', $this->data);
	}

	/**
	 * Return unique slug.
	 *
	 * @return slug
	 */
	public function slug($slug)
	{
		$existPost = Post::where('slug', $slug)->first();

		if (!is_null($existPost)) {
			return $slug.'-'.time();
		}
	}

	public function getPackCatsPosts()
	{
		// get params
        // Declare the rules for the form validation
        $rules = array(
            'cat_id' => 'required|numeric',
            'sort' => 'required',
        );

        // Create a new validator instance from our validation rules
        $validator = Validator::make(Input::all(), $rules);

        // If validation fails, we'll exit the operation now.
        if ($validator->fails()) {
            // Ooops.. something went wrong
            echo "...";
            return false;
        }
        $this->data['cat_id'] = $catId = e(Input::get('cat_id'));
        $this->data['category'] = $category = Category::where('id', $catId)->remember(60)->first();

        if(is_null($catId)) {
            echo "...";
            return false;
        }

        $this->data['orderBy'] = $orderBy = 'posts_position.position ASC';

		$this->data['posts'] = $posts =  $category->rposts()->select('posts.*', 'medias.mpath', 'medias.mtype', 'medias.mname', 'medias.title as mtitle', 'users.first_name', 'users.last_name', 'users.username', 'users.avatar')
			->leftJoin('posts_position', 'posts_position.post_id', '=', 'posts.id')
			->join('users', 'users.id', '=', 'posts.user_id')
			->join('medias', 'medias.id', '=', 'posts.media_id')
			->where('status', 'published')
			->where('post_type', 'post')
            ->where(function ($query) {
                $query->where('posts_position.type', 'category_'.$this->data['category']->id);
            })
            ->orderByRaw($this->data['orderBy'])
            ->where('posts.publish_date', '<=', new Datetime())
            ->groupBy('posts.id')
			->take(25)->remember(20)->get();

		return view('widgets/packcats/cat-view', $this->data);

	}

	public function getSubcats($parentId)
	{
		$this->data['category'] = Category::find($parentId);

		if(!is_null($this->data['category']))
			$this->data['subcats'] = $this->data['category']->subscategories;
		return view('news/frags/flymenu', $this->data);
	}

	public function deleteCache()
	{
		$user = null;

		// Declare the rules for the form validation
		$rules = array(
			'user_id'    => 'required',
			'token' => 'required',
		);

		// Create a new validator instance from our validation rules
		$validator = Validator::make(Input::all(), $rules);

		// If validation fails, we'll exit the operation now.
		if ($validator->fails())
		{
	        // Ooops.. something went wrong
		    return Response::json(array(
		        	'error' => true,
		        	'message' => 'Chýa nh?p thông tin ho?c thông tin ðãng nh?p không h?p l?',
		        ),
		        200
		    );
		}

		$postId = e(Input::get('post_id', 0));
		$type = e(Input::get('type', 'homepage'));
		$user_id = e(Input::get('user_id'));
		$categoryId = e(Input::get('category_id', 0));
		$tagId = e(Input::get('tag_id', 0));
		$token = e(Input::get('token'));

		$user = User::where('id', $user_id)->where('api_token', $token)->first();

		if(is_null($user)) {
		    return Response::json(array(
		        	'error' => true,
		        	'message' => 'Không có quy?n h?n!',
		        ),
		        200
		    );
		}

		$key = '';
		$urls = array();
		if($postId && !is_null($post = Post::find($postId))) {
            $key = 'route-'.str_slug($post->url());
            $url = $post->url().'?no-cache=1&user_id='.$user_id.'&token='.$token;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
			// mobile
			$postUrl = str_replace(App::environment() == 'local' ? 'local.tintuc.vn' : 'tintuc.vn', App::environment() == 'local' ? 'mocal.tintuc.vn' : 'm.tintuc.vn', $url);
            $url = $postUrl.'?no-cache=1&user_id='.$user_id.'&token='.$token;
			$cacheData = file_get_contents($url);
			$urls[] = $url;

	 		usleep(1000);
            // update cache category
            $category = Category::find($post->category_id);
            $param = $category->slug.'?no-cache=1&user_id='.$user_id.'&token='.$token;
            $url = Config::get('app.url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
	 		usleep(1000);
			// mobile
            $url = Config::get('app.mobile_url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
			// Cache::put($key, $cacheData, 15);
	 		usleep(1000);
	        $post_categories = $post->categoryposts()->get();

	        foreach ($post_categories as $cat) {
	            $param = $cat->slug.'?no-cache=1&user_id='.$user_id.'&token='.$token;
            	$url = Config::get('app.url').'/'.$param;
				$cacheData = file_get_contents($url);

				// mobile
            	$url = Config::get('app.mobile_url').'/'.$param;
				$cacheData = file_get_contents($url);
				$urls[] = $url;
	 			usleep(1000);
	        }

		} elseif($type == 'category' && $categoryId) {
            $category = Category::find($categoryId);
	        $param = $category->slug.'?no-cache=1&user_id='.$user_id.'&token='.$token;
            $url = Config::get('app.url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
	 		usleep(1000);

			// mobile
            $url = Config::get('app.mobile_url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
			usleep(1000);

			// delete cache subcate
			foreach($category->subshomecats as $key => $cat)
			{
	            $param = $cat->slug.'?no-cache=1&user_id='.$user_id.'&token='.$token;
            	$url = Config::get('app.url').'/'.$param;
				$cacheData = file_get_contents($url);
				$urls[] = $url;

				// mobile
            	$url = Config::get('app.mobile_url').'/'.$param;
				$cacheData = file_get_contents($url);
				$urls[] = $url;
				usleep(1000);
			}
		} elseif($type == 'tag' && $tagId) {
            $tag = Tag::find($tagId);
	        $param = 'tags/'.$tag->slug.'?no-cache=1&user_id='.$user_id.'&token='.$token;
            $url = Config::get('app.url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;

			// mobile
        	$url = Config::get('app.mobile_url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
	 		usleep(1000);

		} elseif($type == 'homepage') {
            $param = '?no-cache=1&user_id='.$user_id.'&token='.$token;
        	$url = Config::get('app.url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
			usleep(1000);
			// mobile
            $url = Config::get('app.mobile_url').'/'.$param;
			$cacheData = file_get_contents($url);
			$urls[] = $url;
			usleep(1000);
			// Cache::forever($key, $cacheData);
		} else {
			$url = 'all';
			Cache::flush();
		}

	    return Response::json(array(
	        	'error' => false,
	        	'type' => $type,
	        	'post-id' => $postId,
	        	'category-id' => $categoryId,
	        	'tag-id' => $tagId,
	        	'key' => $key,
	        	'url' => $urls,
	        	'token' => $token,
	        	'status' => 1,
	        	'message' => 'Xóa cache thành công!',
	        	'cacheData' => $cacheData,
	        ),
	        200
	    );
	}

	public function getNextPost($next_post_id = 0)
	{
		if($next_post_id != 0):

	        $data = $this->data['postHandler']->getPostData($next_post_id, false);
	        $this->data['data'] = $data;
	        $this->data['post'] = $post = $data->data;

			$this->data['post_tags'] = $post_tags = isset($data->tags) ? $data->tags : null;

			$this->data['parent_category'] = $this->data['category'] = $category = $data->category;

			if($category->parent_id != 0)
				$this->data['parent_category'] = $data->parentcate;

	        // relate select post id
	        $relatePostIds = isset($data->relatePostIds) ? $data->relatePostIds : array();
	        $relatePostIdMerge = $relatePostIds;

	        if($relatePostIds && count($relatePostIds)) {
	        	$this->data['relateposts'] = $this->data['postHandler']->getPostByIds($relatePostIds);
			}

			$this->data['mostview_post'] = $this->data['postHandler']->getHotPostData('view', $this->data['parent_category']->id);

			// Check if the news post exists
			if (is_null($post))
			{
				// If we ended up in here, it means that a page or a news post
				// don't exist. So, this means that it is time for 404 error page.
				return '';
			}
			return view('news/view-next-post', $this->data);
		endif;
	}
	/*
	$relate
	*/
	public function getNextPostID($relate = '',$first_post_id = 0, $curent_post_id = 0)
	{
		if($first_post_id != 0) {
			//n?u t?n t?i session này r?i. th? ki?m tra id bài hi?n t?i ð? lo?i b?. kh?i m?ng relate
			if(Session::has('relate.'.$first_post_id) && count($first_post_id) > 0)
			{
				if(empty($relate)) {
					//n?u empty th? ðang là ? bài nextpost
					$relatePostIds = Session::get('relate.'.$first_post_id);
				} else {
					//n?u ko empty th? ðang là ? bài ð?u tiên
					$relatePostIds = array(0);
		        	$relatePostIds = json_decode($relate);
		        	$relatePostIds = array_reverse($relatePostIds);
				}
				if(($key = array_search($curent_post_id, $relatePostIds)) !== false)
				{
				    unset($relatePostIds[$key]);
				}

				Session::put('relate.'.$first_post_id,$relatePostIds);
				foreach ($relatePostIds as $key => $value) {
					return $value;
					exit();
				}
			} else {
				//chýa có m?ng relate th? t?o m?i nó v?i tên relate.first_post_id
				if(!empty($relate)) {
					$relatePostIds = array(0);
	        		$relatePostIds = json_decode($relate);
	        		$relatePostIds = array_reverse($relatePostIds);
		        	//check n?u có $first_post_id chính nó trong m?ng th? lo?i b? nó.
					if(($key = array_search($first_post_id, $relatePostIds)) !== false) {
					    unset($relatePostIds[$key]);
					}
					//sau ðó lýu vào session v?i tên relate.first_post_id
					Session::put('relate.'.$first_post_id,$relatePostIds);
					//tr? ra id ð?u tiên trong m?ng.
		        	foreach ($relatePostIds as $key => $value) {
						return $value;
						exit();
					}
		        	//làm v?y cho t?i khi h?t giá tr? trong m?ng $relatePostIds; t?c Count($relatePostIds) == 0
				} else {
					return 0;
					exit();
				}
			}
		}
	}

	/**
	 * For 63 province APIv2
	 * @author Dinh.Bang
	 */

	public function getProvinces($proSlug){
		
		//SEO landingpage
        $this->data['seo_landingpage'] = $seo_landingpage = SeoLandingPage();
        $this->data['noindex'] = isset($seo_landingpage->noindex) ? $seo_landingpage->noindex : null;
        //End SEO landingpage

	    $this->data['page']      = $page         = (int)Input::get('page', 1);
	    $this->data['tab']       = $activeTab    = e(Input::get('tab','news'));
	    $this->data['proSlug']   = $proSlug;
	    $ajaxReturn              = array();

	    /* get weather data of province */
	    $location = str_replace('-', '_', $proSlug);
	    $redis = App::make('redis');
	    $keyCache = vsprintf('gadgets:%s', array('weather:'.$location));
	    $data = $redis->get($keyCache);
	    $this->data['weather'] = (isset($data) && strlen($data) > 0) ? (array)json_decode($data) : array();

	    /* su kien nong */
	    $tagHandler = $this->data['tagHandler'];
	    $this->data['featured_tags'] = $fTags = $tagHandler->getTopicFeatureData();/* should pipeline it*/

	    $provinceData =  $tagHandler->getProvincesDatav2($proSlug);
	    if(!isset($provinceData['data']) || empty($provinceData['data'])) {
	        return abort(404);
	    }

	    $listProvinces                  = $tagHandler->getListProvinces();
	    $this->data['listProvinces']    = $listProvinces;
	    $this->data['province']         = $province = json_decode($provinceData['data']);
	    //$province->website              = $tagHandler->getProvinceWebsite($proSlug);/* should pipeline it*/

	    $this->data['totalPost']    = $totalPost = isset($provinceData['total']) ? $provinceData['total'] : 0;
	    $this->data['perPage']      = $perPage   = $provinceData['perPage'];

	    $postArrJson = is_null($provinceData['posts']) ? array() : $provinceData['posts'];
	    $posts = new Paginator($postArrJson, $totalPost, $perPage, null, ['path' => Request::url()]);
	    $this->data['posts'] =  $posts->appends(array('tab'=> 'news'));

	    $arrPosts = $tagHandler->getLocalNewsByProvincev2($proSlug, $page);
	    $localArrJson = ( is_null($arrPosts) || empty($arrPosts) ) ? array() : $arrPosts;
	    $this->data['localNews'] = $localPosts = new Paginator($localArrJson, 10000, 10, null, ['path' => Request::url()]);
	    $this->data['localNews'] = $localPosts->appends(array('tab'=> 'localnews'));
	    
	    return $this->data;
	}

}
