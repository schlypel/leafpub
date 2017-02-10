<?php
/**
 * Leafpub: Simple, beautiful publishing. (https://leafpub.org)
 *
 * @link      https://github.com/Leafpub/leafpub
 * @copyright Copyright (c) 2017 Leafpub Team
 * @license   https://github.com/Leafpub/leafpub/blob/master/LICENSE.md (GPL License)
 */

namespace Leafpub\Models;

use Leafpub\Leafpub,
    Leafpub\Theme,
    Leafpub\Renderer,
    Leafpub\Session,
    Leafpub\Events\Post\Add,
    Leafpub\Events\Post\Added,
    Leafpub\Events\Post\Update,
    Leafpub\Events\Post\Updated,
    Leafpub\Events\Post\Delete,
    Leafpub\Events\Post\Deleted,
    Leafpub\Events\Post\Retrieve,
    Leafpub\Events\Post\Retrieved,
    Leafpub\Events\Post\ManyRetrieve,
    Leafpub\Events\Post\ManyRetrieved,
    Leafpub\Events\Post\BeforeRender;

class Post implements ModelInterface {
    /**
    * Constants
    **/
    const
        ALREADY_EXISTS = 1,
        INVALID_SLUG = 2,
        INVALID_USER = 3,
        NOT_FOUND = 4;

    protected static $_instance = '';

    public static function getModel(){
		if (self::$_instance == null){
			self::$_instance	=	new Tables\Post();
		}
		return self::$_instance;
	}

    public static function getMany(array $options = [], &$pagination = null){
        // Merge options with defaults
        $options = array_merge([
            'author' => null,
            'end_date' => date('Y-m-d H:i:s'),
            'status' => 'published',
            'show_featured' => false,
            'ignore_featured' => false,
            'ignore_sticky' => false,
            'ignore_pages' => true,
            'ignore_posts' => false,
            'items_per_page' => 10,
            'page' => 1,
            'query' => null,
            'start_date' => null,
            'tag' => null,
            'sort' => 'DESC'
        ], (array) $options);
        
        $evt = new ManyRetrieve($options);
        Leafpub::dispatchEvent(ManyRetrieve::NAME, $evt);
        $options = $evt->getEventData();

        // Convert dates to UTC
        if($options['start_date']) $start_date = Leafpub::localToUtc($options['start_date']);
        if($options['end_date']) $end_date = Leafpub::localToUtc($options['end_date']);

        // If there's a query of > 3 chars, make it a fulltext search
        $is_fulltext = mb_strlen($options['query']) > 3;

        $columns = [
            'id', 'slug', 'created', 'pub_date', 'author',
            'title', 'content', 'image', 'meta_title', 'meta_description', 
            'status', 'page', 'featured', 'sticky'
        ];
         
        if($is_fulltext) {
                $columns[] = ['title_score' , new \Zend\Db\Sql\Expression('MATCH(slug, title) AGAINST (' . $options['query'] . ')')];
                $columns[] = ['content_score' , new \Zend\Db\Sql\Expression('MATCH(content) AGAINST (' . $options['query'] . ')')];
        }

        $prefix = Tables\TableGateway::$prefix;
        $select = new \Zend\Db\Sql\Sql(self::getModel()->getAdapter());
        $select = $select->select();
        $select->from($prefix.'view_posts');
        $select->columns($columns);

        $where = function($wh) use($options, $is_fulltext, $prefix){
            // PDO doesn't want to execute the select with 1 = 1'... -_-
            //$wh->expression('1 = 1');

            if($is_fulltext) {
                // Fulltext search
                $wh->expression('MATCH(slug, title, content) AGAINST(?)', $options['query']);
            } else {
                $wh->expression('CONCAT(slug, title) LIKE ?', '%' . $options['query'] . '%');
            }

            if($options['author']){
                $wh->equalTo('author', $options['author']);
            } 

            if($options['tag']){
                $prefix = Tables\TableGateway::$prefix;
                $wh->expression(
                    '(
                        SELECT COUNT(*) from ' . $prefix . 'tags
                        LEFT JOIN ' . $prefix . 'post_tags ON ' . $prefix . 'post_tags.tag = ' . $prefix . 'tags.id
                        WHERE ' . $prefix . 'post_tags.post = ' . $prefix . 'view_posts.id AND ' . $prefix . 'tags.slug = ?
                    ) = 1',
                    $options['tag']
                );
            } 
            
            if($options['status']) {
                //$wh->expression('FIND_IN_SET(status, ?) > 0', implode(',', (array) $options['status']));
                $wh->equalTo('status', $options['status']);
            }

            if($options['ignore_featured']){
                $wh->notEqualTo('featured', 1);
            } 

            if($options['ignore_sticky']){
                $wh->notEqualTo('sticky', 1);
            } 

            if($options['ignore_pages']){
                $wh->notEqualTo('page', 1);
            } 

            if($options['start_date']){
                $wh->greateThanOrEqualTo('pub_date', $options['start_date']);
            }
            
            if($options['end_date']){
                $wh->lessThanOrEqualTo('pub_date', $options['end_date']);
            }
        };

        $select->where($where);

        // Generate order SQL
        if($is_fulltext) {
            $select->order('(title_score * 1.5 + content_score)');
        } else {
            $select->order('sticky '. $options['sort'])->order('pub_date '. $options['sort'])->order('id '. $options['sort']);
        }

        $total_items = self::count($options);

        // Generate pagination
        $pagination = Leafpub::paginate(
            $total_items,
            $options['items_per_page'],
            $options['page']
        );
        $offset = ($pagination['current_page'] - 1) * $pagination['items_per_page'];
        $count = $pagination['items_per_page'];

        $select->offset($offset);
        $select->limit($count);
        // Run the data query
        try {
            $posts = self::getModel()->selectWith($select)->toArray();
        } catch(\PDOException $e) {
            return false;
        }

        foreach($posts as $key => $value){
            $posts[$key] = self::normalize($value);
        }

        $evt = new ManyRetrieved($posts);
        Leafpub::dispatchEvent(ManyRetrieved::NAME, $evt);
        $posts = $evt->getEventData();

        return $posts;
    }

    public static function getOne($slug){
        $evt = new Retrieve($slug);
        Leafpub::dispatchEvent(Retrieve::NAME, $evt);

        // Retrieve the post
        try {
            $prefix = Tables\TableGateway::$prefix;
            $select = new \Zend\Db\Sql\Sql(self::getModel()->getAdapter());
            $select = $select->select();
            $select->from($prefix.'view_posts')
                   ->where(['slug' => $slug]);
            $post = self::getModel()->selectWith($select)->current()->getArrayCopy();
            if(!$post) return false;
        } catch(\PDOException $e) {
            return false;
        }

        // Normalize fields
        $post = self::normalize($post);

        $evt = new Retrieved($post);
        Leafpub::dispatchEvent(Retrieved::NAME, $evt);
        return $evt->getEventData();
    }

    public static function create($post){
        // Enforce slug syntax
        $slug = $post['slug'];
        $slug = Leafpub::slug($slug);

        // Is the slug valid?
        if(!mb_strlen($slug) || Leafpub::isProtectedSlug($slug)) {
            throw new \Exception('Invalid slug: ' . $slug, self::INVALID_SLUG);
        }

        // Does a post already exist here?
        if(self::exists($slug)) {
            throw new \Exception('Post already exists: ' . $slug, self::ALREADY_EXISTS);
        }

        // Parse publish date format and convert to UTC
        $post['pub_date'] = Leafpub::localToUtc(self::parseDate($post['pub_date']));

        // Translate author slug to ID
        $post['author'] = User::getId($post['author']);
        if(!$post['author']) {
            throw new \Exception('Invalid user.', self::INVALID_USER);
        }

        // Empty title defaults to settings.default_title
        if(empty($post['title'])) {
            $post['title'] = Setting::getOne('default_title');
        }

        // Empty content defaults to settings.default_content
        if(empty($post['content'])) {
            $post['content'] = Setting::getOne('default_content');
        }

        // Don't allow null properties
        $post['image'] = Upload::getImageId($post['image']);
        $post['meta_title'] = (string) $post['meta_title'];
        $post['meta_description'] = (string) $post['meta_description'];

        // Status must be `published` or `draft`
        if($post['status'] !== 'draft') $post['status'] = 'published';

        // Page, featured, and sticky must be 1 or 0
        foreach(['page', 'featured', 'sticky'] as $key) {
            $post[$key] = filter_var($post[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        $evt = new Add($post);
        Leafpub::dispatchEvent(Add::NAME, $evt);
        $post = $evt->getEventData();

        try {
            $model = self::getModel();
            $model->insert($post);
            $post_id = (int) $model->lastInsertValue();
            if($post_id <= 0) return false;
        } catch(\PDOException $e) {
            throw new \Exception('Database error: ' . $e->getMessage());
        }

        // Set post tags
        self::setTags($post_id, $post['tags']);
        self::setImageToPost($post_id, $post['content']);

        // Create the initial revision
        History::add($slug, true);
        
        $evt = new Added($post_id);
        Leafpub::dispatchEvent(Added::NAME, $evt);
        
        return $post_id;
    }

    public static function edit($properties){
        $slug = $properties['slug'];
        // Get the post
        $post = self::get($slug);
        if(!$post) {
            throw new \Exception('Post not found: ' . $slug, self::NOT_FOUND);
        }

        // Merge options
        $post = array_merge($post, $properties);

        // Parse publish date format and convert to UTC
        $post['pub_date'] = Leafpub::localToUtc(self::parseDate($post['pub_date']));

        // Translate author slug to ID
        $post['author'] = User::getId($post['author']);
        if(!$post['author']) {
            throw new \Exception('Invalid user.', self::INVALID_USER);
        }

        // Empty title defaults to settings.default_title
        if(empty($post['title'])) {
            $post['title'] = Setting::getOne('default_title');
        }

        // Empty content defaults to settings.default_content
        if(empty($post['content'])) {
            $post['content'] = Setting::getOne('default_content');
        }

        // Don't allow null properties
        $post['image'] = Upload::getImageId($post['image']);
        $post['meta_title'] = (string) $post['meta_title'];
        $post['meta_description'] = (string) $post['meta_description'];

        // Status must be `published` or `draft`
        if($properties['status'] !== 'draft') $properties['status'] = 'published';

        // Page, featured, and sticky must be 1 or 0
        foreach(['page', 'featured', 'sticky'] as $key) {
            $post[$key] = filter_var($post[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        // Change the slug?
        if($post['slug'] !== $slug) {
            // Enforce slug syntax
            $post['slug'] = self::slug($post['slug']);

            // Is the slug valid?
            if(!mb_strlen($post['slug']) || Leafpub::isProtectedSlug($post['slug'])) {
                throw new \Exception('Invalid slug: ' . $post['slug'], self::INVALID_SLUG);
            }

            // Does a post already exist here?
            if(self::exists($post['slug'])) {
                throw new \Exception('Post already exists: ' . $post['slug'], self::ALREADY_EXISTS);
            }

            // If this is the custom homepage, update settings
            if(Setting::getOne('homepage') === $slug) {
                Setting::update('homepage', $post['slug']);
            }
        }

        $evt = new Update($post);
        Leafpub::dispatchEvent(Update::NAME, $evt);
        $post = $evt->getEventData();

        try {
            self::getModel()->update($post, ['slug' => $slug]);
        } catch(\PDOException $e) {
            throw new \Exception('Database error: ' . $e->getMessage());
        }

        // Set post tags
        self::setTags($post['id'], $post['tags']);
        self::setImageToPost($post['id'], $post['content']);

        // Create a revision
        History::add($post['slug']);

        $evt = new Updated($post['id']);
        Leafpub::dispatchEvent(Updated::NAME, $evt);

        return true;
    }

    public static function delete($slug){
        // If this post is the custom homepage, update settings
        if($slug === Setting::getOne('homepage')) {
            Setting::update('homepage', '');
        }

        $evt = new Delete($slug);
        Leafpub::dispatchEvent(Delete::NAME, $evt);

        // Delete the post
        try {
            // Cleanup history
            History::flush($slug);

            $rowCount = self::getModel()->delete(['slug' => $slug]);

            //TODO: Delete post_uploads
            
            if($rowCount === 0) return false;
        } catch(\PDOException $e) {
            return false;
        }

        $evt = new Deleted($slug);
        Leafpub::dispatchEvent(Deleted::NAME, $evt);

        return true;
    }

     /**
    * Returns the total number of posts that exist
    *
    * @param array $options
    * @return mixed
    *
    **/
    public static function count($options = null) {
        // Merge options
        $options = array_merge([
            'author' => null,
            'end_date' => date('Y-m-d H:i:s'),
            'status' => 'published',
            'show_featured' => false,
            'ignore_featured' => false,
            'ignore_sticky' => false,
            'ignore_pages' => true,
            'start_date' => null,
            'tag' => null
        ], (array) $options);

        $prefix = Tables\TableGateway::$prefix;
        $select = new \Zend\Db\Sql\Sql(self::getModel()->getAdapter());
        $select = $select->select();
        $select->from($prefix.'view_posts');

        // Convert dates to UTC
        if($options['start_date']) $start_date = Leafpub::localToUtc($options['start_date']);
        if($options['end_date']) $end_date = Leafpub::localToUtc($options['end_date']);

        $where = function($wh) use($options, $prefix){
            // Add options to query
            if($options['author']){
                $wh->equalTo('author', $options['author']);
            } 

            if($options['tag']){
                $wh->expression(
                    '(
                        SELECT COUNT(*) from ' . $prefix . 'tags
                        LEFT JOIN ' . $prefix . 'post_tags ON ' . $prefix . 'post_tags.tag = ' . $prefix . 'tags.id
                        WHERE ' . $prefix . 'post_tags.post = ' . $prefix . 'view_posts.id AND ' . $prefix . 'tags.slug = ?
                    ) = 1',
                    $options['tag']
                );
            } 
            
            if($options['status']) {
                //$wh->expression('FIND_IN_SET(status, ?) > 0', implode(',', (array) $options['status']));
                $wh->equalTo('status', $options['status']);
            }

            if($options['ignore_featured']){
                $wh->notEqualTo('featured', 1);
            } 

            if($options['ignore_sticky']){
                $wh->notEqualTo('sticky', 1);
            } 

            if($options['ignore_pages']){
                $wh->notEqualTo('page', 1);
            } 

            if($options['start_date']){
                $wh->greateThanOrEqualTo('pub_date', $options['start_date']);
            }
            
            if($options['end_date']){
                $wh->lessThanOrEqualTo('pub_date', $options['end_date']);
            }
        };

        // Fetch results
        try {
            $model = self::getModel();
            $select->columns(['num' => new \Zend\Db\Sql\Expression('COUNT(*)')]);
            if ($where !== null){
                $select->where($where);
            }
            $ret =  $model->selectWith($select);
            return $ret->current()['num'];
        } catch(\PDOException $e) {
            return false;
        }
    }

    /**
    * Tells whether a post exists
    *
    * @param String $slug
    * @return bool
    *
    **/
    public static function exists($slug) {
        try {
            $ret = self::getModel()->select(['slug' => $slug]);
            return !!$ret->current();
        } catch(\PDOException $e) {
            return false;
        }
    }
    
    /**
    * Gets one public post immediately before or after the target post
    *
    * @param String $slug
    * @param null $options
    * @return mixed
    *
    **/
    public static function getAdjacent($slug, $options = null) {
        // Merge options
        $options = array_merge([
            'author' => null,
            'direction' => 'next',
            'end_date' => date('Y-m-d H:i:s'),
            'status' => 'published',
            'ignore_featured' => false,
            'ignore_sticky' => false,
            'ignore_pages' => true,
            'start_date' => null,
            'tag' => null
        ], (array) $options);

        $model = self::getModel();
        
        $prefix = Tables\TableGateway::$prefix;
        $select = new \Zend\Db\Sql\Sql(self::getModel()->getAdapter());
        $select = $select->select();
        $select->from($prefix.'view_posts');

        // Convert dates to UTC
        if($options['start_date']) $start_date = Leafpub::localToUtc($options['start_date']);
        if($options['end_date']) $end_date = Leafpub::localToUtc($options['end_date']);

        $where = function($wh) use($options, $prefix, $slug){
            if($options['author']){
                $wh->equalTo('author', $options['author']);
            }

            if($options['tag']){
                $wh->expression(
                    '(
                        SELECT COUNT(*) from ' . $prefix . 'tags
                        LEFT JOIN ' . $prefix . 'post_tags ON ' . $prefix . 'post_tags.tag = ' . $prefix . 'tags.id
                        WHERE ' . $prefix . 'post_tags.post = ' . $prefix . 'view_posts.id AND ' . $prefix . 'tags.slug = ?
                    ) = 1',
                    $options['tag']
                );
            } 

            if($options['status']) {
                $wh->expression('FIND_IN_SET(status, ?) > 0', implode(',', (array) $options['status']));
            }

            if($options['ignore_featured']){
                $wh->notEqualTo('featured', 1);
            } 

            if($options['ignore_sticky']){
                $wh->notEqualTo('sticky', 1);
            } 

            if($options['ignore_pages']){
                $wh->notEqualTo('page', 1);
            } 

            if($options['start_date']){
                $wh->greaterThanOrEqualTo('pub_date', $options['start_date']);
            } 

            if($options['end_date']){
                $wh->lessThanOrEqualTo('pub_date', $options['end_date']);
            } 

            // Determine direction
            $sort = $options['direction'] === 'next' ? 'ASC' : 'DESC';
            $compare = $options['direction'] === 'next' ? '>=' : '<=';
        
            $wh->notEqualTo('slug', $slug);

            $wh->expression(
                'CONCAT(pub_date, id) ' . $compare . ' (
                    SELECT CONCAT(pub_date, id)
                    FROM ' . $prefix . 'view_posts
                    WHERE slug = ?
                )',
                $slug);
        };

        $select->where($where);
        $select->order('pub_date');
        $select->limit(1);

        try {
            $post = $model->selectWith($select)->toArray()[0];
            if(!$post) return false;
        } catch(\PDOException $e) {
            return false;
        }

        // Normalize fields
        $post = self::normalize($post);

        return $post;
    }

     /**
    * Gets suggested posts for the target post
    *
    * @param String $slug
    * @param null $options
    * @return mixed
    *
    **/
    public static function getSuggested($slug, $options = null) {
        // Merge options
        $options = array_merge([
            'author' => null,
            'end_date' => date('Y-m-d H:i:s'),
            'status' => 'published',
            'ignore_featured' => false,
            'ignore_sticky' => false,
            'ignore_pages' => true,
            'max' => 5,
            'start_date' => null,
            'tag' => null
        ], (array) $options);

        // Convert dates to UTC
        if($options['start_date']) $start_date = Leafpub::localToUtc($options['start_date']);
        if($options['end_date']) $end_date = Leafpub::localToUtc($options['end_date']);

        $prefix = Tables\TableGateway::$prefix;
        $select = new \Zend\Db\Sql\Sql(self::getModel()->getAdapter());
        $select = $select->select();
        $select->from($prefix.'view_posts');
        $select->join(['b' => $prefix.'post_tags'], 'b.post = ' . $prefix . 'view_posts.id', [], 'left');

        $where = function($wh) use($options, $prefix, $slug){
            $wh->notEqualTo($prefix.'view_posts.slug', $slug);

            if($options['author']){
                $wh->equalTo('author', $options['author']);
            }

            if($options['tag']){
                $wh->expression(
                    '(
                        SELECT COUNT(*) from ' . $prefix . 'tags
                        LEFT JOIN ' . $prefix . 'post_tags ON ' . $prefix . 'post_tags.tag = ' . $prefix . 'tags.id
                        WHERE ' . $prefix . 'post_tags.post = ' . $prefix . 'view_posts.id AND slug = ?
                    ) = 1',
                    $options['tag']
                );
            } 

            if($options['status']) {
                $wh->expression('FIND_IN_SET(status, ?) > 0', implode(',', (array) $options['status']));
            }

            if($options['ignore_featured']){
                $wh->notEqualTo('featured', 1);
            } 

            if($options['ignore_sticky']){
                $wh->notEqualTo('sticky', 1);
            } 

            if($options['ignore_pages']){
                $wh->notEqualTo('page', 1);
            } 

            if($options['start_date']){
                $wh->greaterThanOrEqualTo('pub_date', $options['start_date']);
            } 

            if($options['end_date']){
                $wh->lessThanOrEqualTo('pub_date', $options['end_date']);
            } 

            $table = new Tables\PostTags();
            $select1 = $table->getSql()->select()
                                       ->columns(['tag'])
                                       ->join(['c' => $prefix.'posts'], $prefix.'post_tags.post = c.id', [], 'left')
                                       ->where(function($wh) use($slug){
                                            $wh->equalTo('c.slug', $slug);
                                       });
            
            $wh->in('b.tag', $select1);
        };

        $select->where($where)
               ->order($prefix.'view_posts.pub_date')
               ->limit($options['max']);

        // Get matching posts
        try {
            $model = self::getModel();
            $posts = $model->selectWith($select)->toArray();
            if(!$posts) return false;
        } catch(\PDOException $e) {
            return false;
        }

        // Normalize fields
        foreach($posts as $key => $value) {
            $posts[$key] = self::normalize($value);
        }

        return $posts;
    }

    /**
    * Tells whether or not a post is e to the public
    *
    * @param String $post_or_slug
    * @return bool
    *
    **/
    public static function isVisible($post_or_slug) {
        // Get the post
        $post = is_string($post_or_slug) ? self::getOne($post_or_slug) : $post_or_slug;
        if(!$post) return false;

        // Make sure pub date is a valid date format
        $post['pub_date'] = Leafpub::parseDate($post['pub_date']);
        $pub_date = new \DateTime($post['pub_date']);
        $pub_date->setTimeZone(new \DateTimeZone('UTC'));

        // Is it in the future?
        $now = new \DateTime('now');
        $now->setTimeZone(new \DateTimeZone('UTC'));
        if($pub_date > $now) return false;

        // Is is published?
        if($post['status'] !== 'published') return false;

        return true;
    }

    /**
    * Renders a post
    *
    * @param String $slug_or_post
    * @param null $options
    * @return mixed
    *
    **/
    public static function render($slug_or_post, $options = null) {
        // Get the post
        if(is_array($slug_or_post)) {
            $post = $slug_or_post;
        } else {
            $post = self::getOne($slug_or_post);
            if(!$post) return false;
        }

        // Get the author
        $author = User::getOne($post['author']);

        // Make sure pub date is a valid date format
        $post['pub_date'] = Leafpub::parseDate($post['pub_date']);

        // Only render if it's visible to the public or a preview
        if(!self::isVisible($post) && !$options['preview']) return false;

        // Determine which template to use
        if($options['zen']) {
            $template = Leafpub::path('source/templates/editor.zen.hbs');
        } else {
            $template = Theme::getPath($post['page'] ? 'page.hbs' : 'post.hbs');
        }

        // Generate event
        $beforeRender = new BeforeRender([
            'post' => $post,
            'special_vars' => [
                'meta' => [
                    'editable' => !!$options['editable'],
                    'preview' => !!$options['preview'],
                    'title'=> !empty($post['meta_title']) ? $post['meta_title'] : $post['title'],
                    'description' => !empty($post['meta_description']) ?
                        $post['meta_description'] :
                        Leafpub::getChars(strip_tags($post['content']), 160),
                    // JSON linked data (schema.org)
                    'ld_json' => [
                        '@context' => 'https://schema.org',
                        '@type' => 'Article',
                        "mainEntityOfPage" => [
                            "@type" => "WebPage",
                            "@id" => self::url($post['slug'])
                        ],
                        'publisher' => [
                            '@type' => 'Organization',
                            'name' => Setting::getOne('title'),
                            'logo' => !empty(Setting::getOne('logo')) ?
                                [
                                    '@type' => 'ImageObject',
                                    'url' => Leafpub::url(Setting::getOne('logo'))
                                 ] : null
                            ],
                        'author' => [
                            '@type' => 'Person',
                            'name' => $author['name'],
                            'description' => strip_tags(Leafpub::markdownToHtml($author['bio'])),
                            'image' => !empty($author['avatar']) ?
                                [
                                    '@type' => 'ImageObject',
                                    'url' => Leafpub::url($author['avatar'])
                                ] : null,
                            'sameAs' => !empty($author['website']) ?
                                [$author['website']] : null,
                        ],
                        'url' => self::url($post['slug']),
                        'headline' => !empty($post['meta_title']) ?
                            $post['meta_title'] :
                            $post['title'],
                        'description' => !empty($post['meta_description']) ?
                            $post['meta_description'] :
                            Leafpub::getWords(strip_tags($post['content']), 50),
                        'image' => empty($post['image']) ? null : [
                                '@type' => 'ImageObject',
                                'url' => Leafpub::url($post['image']),
                                'width' => 0,
                                'height' => 0
                            ],
                        'datePublished' => Leafpub::strftime('%FT%TZ', strtotime($post['pub_date'])),
                        'dateModified' => Leafpub::strftime('%FT%TZ', strtotime($post['pub_date']))
                    ],
                    'open_graph' => [
                        'og:type' => 'article',
                        'og:site_name' => Setting::getOne('title'),
                        'og:title' => !empty($post['meta_title']) ?
                            $post['meta_title'] :
                            $post['title'],
                        'og:description' => !empty($post['meta_description']) ?
                            $post['meta_description'] :
                            Leafpub::getWords(strip_tags($post['content']), 50),
                        'og:url' => self::url($post['slug']),
                        'og:image' => empty($post['image']) ? '' : Leafpub::url($post['image']),
                        'article:published_time' => $post['page'] ?
                            null : Leafpub::strftime('%FT%TZ', strtotime($post['pub_date'])),
                        'article:modified_time' => $post['page'] ?
                            null : Leafpub::strftime('%FT%TZ', strtotime($post['pub_date'])),
                        'article:tag' => $post['page'] ?
                            null : implode(', ', (array) $post['tags'])
                    ],
                    'twitter_card' => [
                        'twitter:card' => !empty($post['image']) ?
                            'summary_large_image' :
                            'summary',
                        'twitter:site' => !empty(Setting::getOne('twitter')) ?
                            '@' . Setting::getOne('twitter') : null,
                        'twitter:title' => !empty($post['meta_title']) ?
                            $post['meta_title'] :
                            $post['title'],
                        'twitter:description' => !empty($post['meta_description']) ?
                            $post['meta_description'] :
                            Leafpub::getWords(strip_tags($post['content']), 50),
                        'twitter:creator' => !empty($author) ?
                            '@' . $author['twitter'] : null,
                        'twitter:url' => self::url($post['slug']),
                        'twitter:image' => !empty($post['image']) ?
                            Leafpub::url($post['image']) :
                            null,
                        'twitter:label1' => !$post['page'] ?
                            'Written by' : null,
                        'twitter:data1' => !$post['page'] ?
                            $author['name'] : null,
                        'twitter:label2' => !$post['page'] ?
                            'Tagged with' : null,
                        'twitter:data2' => !$post['page'] ?
                            implode(', ', (array) $post['tags']) : null
                    ]
                ]
            ],
        ]);
        // Dispatch Event
        Leafpub::dispatchEvent(BeforeRender::NAME, $beforeRender);
        // Get data from our dispatched event...
        $data = $beforeRender->getEventData();

        // Render it
        $html = Renderer::render([
            'template' => $template,
            'data' => [
                'post' => $data['post']
            ],
            'special_vars' => $data['special_vars'],
            
            'helpers' => ['theme', 'url', 'utility'],
            // If we're editing or previewing, don't pass in user data to simulate what an
            // unauthenticated user would see.
            'user' => ($options['editable'] || $options['preview']) ? false : Session::user()
        ]);

        // Check for required helpers
        if($options['editable']) {
            // There's no reliable way to check for the existence of a Handlebar helper without
            // parsing the template file and all of the partials it uses before rendering. As a
            // workaround, we check for certain known strings that will be in the HTML when a post
            // is rendered.
            foreach([
                // The helper       // The string to search for
                '{{title editable="true"}}'         => '<div data-leafpub-id="post:title"',
                '{{content editable="true"}}'       => '<div data-leafpub-id="post:content"',
                '{{leafpub_head}}' => '<!--{{leafpub_head}}-->',
                '{{leafpub_foot}}' => '<!--{{leafpub_foot}}-->'
            ] as $helper => $test_string ) {
                if(!mb_strstr($html, $test_string)) {
                    throw new \Exception("The $helper helper is missing in $template.");
                }
            }
        }

        // Append a <base> tag for editable posts and post previews so they render properly no
        // matter where the rendering document exists.
        if($options['editable'] || $options['preview']) {
            $html = str_replace(
                '<!--{{leafpub_head}}-->',
                '<!--{{leafpub_head}}--><base href="' .
                    // The base should always end with a slash
                    htmlspecialchars(rtrim(self::url(), '/')) . '/">',
                $html
            );
        }

        return $html;
    }

    public static function getPostsToTag($tagId){
        try {
            $table = new Tables\PostTags();
            $select1 = $table->getSql()->select()
                                        ->columns(['post'])
                                        ->where(function($wh) use($tagId){
                                            $wh->equalTo('tag', $tagId);
                                        });

            $model = self::getModel();
            $select = self::getModel()->getSql()->select()
                                                ->columns(['slug'])
                                                ->where(function($wh) use($select1){
                                                    $wh->in('id', $select1);
                                                });
           
            return $model->selectWith($select)->toArray();
        } catch(\Exception $e){
            return false;
        }
    }

    public static function getPostsToUpload($mediaId){
        try {
            $table = new Tables\PostUploads();
            $select1 = $table->getSql()->select()
                                        ->columns(['post'])
                                        ->where(function($wh) use($mediaId){
                                            $wh->equalTo('upload', $mediaId);
                                        });

            $model = self::getModel();
            $select = self::getModel()->getSql()->select()
                                                ->columns(['slug'])
                                                ->where(function($wh) use($select1){
                                                    $wh->in('id', $select1);
                                                });
           
            return $model->selectWith($select)->toArray();
        } catch(\Exception $e){
            return false;
        }
    }

    /**
    * Gets the tags for the specified post.
    *
    * @param int $post_id
    * @return mixed
    *
    **/
    private static function getTags($post_id) {
        try {
           return Tag::getTagsToPost($post_id);
       } catch(\PDOException $e) {
           return false;
       }
    }

    /**
    * Gets the uploads for the specified post.
    *
    * @param int $post_id
    * @return mixed
    *
    **/
    private static function getUploads($post_id) {
        try {
           return Upload::getUploadsToPost($post_id);
       } catch(\PDOException $e) {
           return false;
       }
    }

    /**
    * Normalize data types for certain fields
    *
    * @param array $post
    * @return array
    *
    **/
    private static function normalize($post) {
        // Cast to integer
        $post['id'] = (int) $post['id'];
        $post['page'] = (int) $post['page'];
        $post['featured'] = (int) $post['featured'];
        $post['sticky'] = (int) $post['sticky'];

        // Convert dates from UTC to local
        $post['created'] = Leafpub::utcToLocal($post['created']);
        $post['pub_date'] = Leafpub::utcToLocal($post['pub_date']);

        // Append tags
        $post['tags'] = self::getTags($post['id']);
        $posts['media'] = self::getUploads($post['id']);

        return $post;
    }

    /**
    * Sets the tags for the specified post. To remove all tags, call this method with $tags = null.
    *
    * @param int $post_id
    * @param null $tags
    * @return bool
    *
    **/
    private static function setTags($post_id, $tags = null) {
        $table = new Tables\PostTags();
        
        // Remove old tags
        try {
            $table->delete(['post' => $post_id]);
        } catch(\PDOException $e) {
            return false;
        }

        // Assign new tags
        if(count($tags)) {
            // Escape slugs
            /*foreach($tags as $key => $value) {
                $tags[$key] = self::$database->quote($value);
            }*/
            // Assign tags
            try {
                foreach($tags as $tag){
                    $data = ['post' => $post_id, 'tag' => Tag::getId($tag)];
                    $table->insert($data);
                }
            } catch(\PDOException $e) {
                return false;
            }
        }

        return true;
    }

    /**
    * Save the image relations
    *
    * @param int $post_id
    * @param String $content
    * @return bool
    *
    */
    private static function setImageToPost($post_id, $content){
        $table = new Table\PostUploads();

        try {
            $table->delete(['post' => $post_id]);
        } catch(\PDOException $e) {
            return false;
        }

        $matches = [];
        $doc = new \DOMDocument();
        @$doc->loadHTML($content);

        $tags = $doc->getElementsByTagName('img');

        foreach ($tags as $tag) {
             array_push($matches, $tag->getAttribute('src'));
        }

        if (count($matches)){
            // Escape slugs
           /* foreach($matches as $key => $value) {
                $matches[$key] = self::$database->quote($value);
            }*/
            // Assign tags
            try {
                foreach($matches as $media){
                    $data = ['post' => $post_id, 'upload' => Upload::getId($media)];
                    $table->insert($data);
                }
            } catch(\PDOException $e) {
                return false;
            }
        }

        return true;
    }
    
    /**
    * Returns a post URL
    *
    * @param String $slug
    * @return String
    *
    **/
    public static function url($slug = '') {
        // example.com/slug
        return Leafpub::url($slug);
    }
}