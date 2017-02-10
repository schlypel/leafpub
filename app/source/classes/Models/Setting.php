<?php
/**
 * Leafpub: Simple, beautiful publishing. (https://leafpub.org)
 *
 * @link      https://github.com/Leafpub/leafpub
 * @copyright Copyright (c) 2017 Leafpub Team
 * @license   https://github.com/Leafpub/leafpub/blob/master/LICENSE.md (GPL License)
 */

namespace Leafpub\Models;

class Setting extends AbstractModel {
    protected static $_instance;
    protected static $settings = [];

    protected static function getModel(){
		if (self::$_instance == null){
			self::$_instance	=	new Tables\Setting();
		}
		return self::$_instance;
	}

    /**
    * Returns all settings in an array
    *
    * @return array
    *
    **/
    public static function getMany(array $options = [], &$pagination = null){
        return self::$settings;
    }

    /**
    * Gets a single setting
    *
    * @param null $name
    * @return mixed
    *
    **/
    public static function getOne($name){
        return isset(self::$settings[$name]) ? self::$settings[$name] : null;
    }

    /**
    * Alias for edit
    *
    * @param array $data
    * @return bool
    *
    **/
    public static function create($data){
        return self::edit($data);
    }

    public static function edit($data){
        // Update the database
        try {
            self::getModel()->update(['value' => $data['value']], ['name' => $data['name']]);
        } catch(\PDOException $e) {
            return false;
        }
        // Update cache
        self::$settings[$name] = $value;

        return true;
    }

    /**
    * Delete a setting
    *
    * @param String $name
    * @return bool
    *
    **/
    public static function delete($name){
        try{
            self::getModel()->delete(['name' => $name]);
        } catch (\Exception $e){
            return false;
        }
        unset(self::$settings[$name]);
        return true;
    }

    /**
    * Load settings from the database and store in a static variable for quick access
    *
    * @return array
    *
    **/
    public static function load() {
        try {
            $ret = self::getModel()->select()->toArray();
            foreach($ret as $ds){
                self::$settings[$ds['name']] = $ds['value'];
            }
        } catch(\PDOException $e) {
            throw new \Exception('Unable to load settings from the database.');
        }
    }
}