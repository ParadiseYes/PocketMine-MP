<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace PocketMine\Plugin;

use PocketMine\Permission\Permissible;
use PocketMine\Permission\Permission;
use PocketMine;

/**
 * Manages all the plugins, Permissions and Permissibles
 */
class PluginManager{

	/**
	 * @var PluginManager
	 */
	private static $instance = null;
	
	/**
	 * @var Plugin[]
	 */
	protected $plugins = array();

	/**
	 * @var Permission[]
	 */
	protected $permissions = array();

	/**
	 * @var Permission[]
	 */
	protected $defaultPerms = array();

	/**
	 * @var Permission[]
	 */
	protected $defaultPermsOp = array();

	/**
	 * @var Permissible[]
	 */
	protected $permSubs = array();

	/**
	 * @var Permissible[]
	 */
	protected $defSubs = array();

	/**
	 * @var Permissible[]
	 */
	protected $defSubsOp = array();

	/**
	 * @var PluginLoader[]
	 */
	protected $fileAssociations = array();

	/**
	 * @return PluginManager
	 */
	public static function getInstance(){
		return self::$instance;
	}
	
	/**
	 * @param string $name
	 *
	 * @return null|Plugin
	 */
	public function getPlugin($name){
		if(isset($this->plugins[$name])){
			return $this->plugins[$name];
		}

		return null;
	}

	/**
	 * @param string|PluginLoader $loader
	 *
	 * @return boolean
	 */
	public function registerInterface($loader){
		if(is_object($loader) and !($loader instanceof PluginLoader)){
			return false;
		}elseif(is_string($loader)){
			if(is_subclass_of($loader, "PocketMine\\Plugin\\PluginLoader")){
				$loader = new $loader;
			}else{
				return false;
			}
		}

		$this->fileAssociations[spl_object_hash($loader)] = array($loader, $loader->getPluginFilters());

		return true;
	}

	/**
	 * @return Plugin[]
	 */
	public function getPlugins(){
		return $this->plugins;
	}

	/**
	 * @param string $path
	 *
	 * @return Plugin
	 */
	public function loadPlugin($path){
		foreach($this->fileAssociations as $loader){
			if(preg_match($loader[1], basename($path)) > 0){
				$description = $loader[0]->getPluginDescription($path);
				if($description instanceof PluginDescription){
					return $loader[0]->loadPlugin($path);
				}
			}
		}

		return null;
	}

	/**
	 * @param $directory
	 *
	 * @return Plugin[]
	 */
	public function loadPlugins($directory){
		if(is_dir($directory)){
			$plugins = array();
			$loadedPlugins = array();
			$dependencies = array();
			$softDependencies = array();
			foreach(new \IteratorIterator(new \DirectoryIterator($directory)) as $file){
				foreach($this->fileAssociations as $loader){
					if(preg_match($loader[1], basename($file)) > 0){
						$description = $loader[0]->getPluginDescription($file);
						if($description instanceof PluginDescription){
							$name = $description->getName();
							if(stripos($name, "pocketmine") !== false or stripos($name, "minecraft") !== false or stripos($name, "mojang") !== false){
								console("[ERROR] Could not load plugin '" . $name . "': restricted name");
								continue;
							}elseif(strpos($name, " ") !== false){
								console("[WARNING] Plugin '" . $name . "' uses spaces in its name, this is discouraged");
							}
							if(isset($plugins[$name])){
								console("[ERROR] Could not load duplicate plugin '" . $name . "': plugin exists");
								continue;
							}

							$compatible = false;

							//Check multiple dependencies
							foreach($description->getCompatibleApis() as $version){
								//Format: majorVersion.minorVersion.patch
								$version = array_map("intval", explode(".", $version));
								$apiVersion = array_map("intval", explode(".", PocketMine\API_VERSION));

								//Completely different API version
								if($version[0] !== $apiVersion[0]){
									continue;
								}

								//If the plugin requires new API features, being backwards compatible
								if($version[1] > $apiVersion[1]){
									continue;
								}

								$compatible = true;
								break;
							}

							if($compatible === false){
								console("[ERROR] Could not load plugin '" . $name . "': API version not compatible");
								continue;
							}

							$plugins[$name] = $file;

							$softDependencies[$name] = (array) $description->getSoftDepend();
							$dependencies[$name] = (array) $description->getDepend();

							foreach($description->getLoadBefore() as $before){
								if(isset($softDependencies[$before])){
									$softDependencies[$before][] = $name;
								}else{
									$softDependencies[$before] = array($name);
								}
							}

							break;
						}
					}
				}
			}

			while(count($plugins) > 0){
				$missingDependency = true;
				foreach($plugins as $name => $file){
					if(isset($dependencies[$name])){
						foreach($dependencies[$name] as $key => $dependency){
							if(isset($loadedPlugins[$dependency])){
								unset($dependencies[$name][$key]);
							}elseif(!isset($plugins[$dependency])){
								console("[SEVERE] Could not load plugin '" . $name . "': Unknown dependency");
								break;
							}
						}

						if(count($dependencies[$name]) === 0){
							unset($dependencies[$name]);
						}
					}

					if(isset($softDependencies[$name])){
						foreach($softDependencies[$name] as $key => $dependency){
							if(isset($loadedPlugins[$dependency])){
								unset($softDependencies[$name][$key]);
							}
						}

						if(count($softDependencies[$name]) === 0){
							unset($softDependencies[$name]);
						}
					}

					if(!isset($dependencies[$name]) and !isset($softDependencies[$name])){
						unset($plugins[$name]);
						$missingDependency = false;
						if($plugin = $this->loadPlugin($file) and $plugin instanceof Plugin){
							$loadedPlugins[$name] = $plugin;
						}else{
							console("[SEVERE] Could not load plugin '" . $name . "'");
						}
					}
				}

				if($missingDependency === true){
					foreach($plugins as $name => $file){
						if(!isset($dependencies[$name])){
							unset($softDependencies[$name]);
							unset($plugins[$name]);
							$missingDependency = false;
							if($plugin = $this->loadPlugin($file) and $plugin instanceof Plugin){
								$loadedPlugins[$name] = $plugin;
							}else{
								console("[SEVERE] Could not load plugin '" . $name . "'");
							}
						}
					}

					//No plugins loaded :(
					if($missingDependency === true){
						foreach($plugins as $name => $file){
							console("[SEVERE] Could not load plugin '" . $name . "': circular dependency detected");
						}
						$plugins = array();
					}
				}
			}

			return $loadedPlugins;
		}else{
			return array();
		}
	}

	/**
	 * @param string $name
	 *
	 * @return null|Permission
	 */
	public function getPermission($name){
		if(isset($this->permissions[$name])){
			return $this->permissions[$name];
		}

		return null;
	}

	/**
	 * @param Permission $permission
	 *
	 * @return bool
	 */
	public function addPermission(Permission $permission){
		if(!isset($this->permissions[$permission->getName()])){
			$this->permissions[$permission->getName()] = $permission;
			$this->calculatePermissionDefault($permission);

			return true;
		}

		return false;
	}

	/**
	 * @param string|Permission $permission
	 */
	public function removePermission($permission){
		if($permission instanceof Permission){
			unset($this->permissions[$permission->getName()]);
		}else{
			unset($this->permissions[$permission]);
		}
	}

	/**
	 * @param boolean $op
	 *
	 * @return Permission[]
	 */
	public function getDefaultPermissions($op){
		if($op === true){
			return $this->defaultPermsOp;
		}else{
			return $this->defaultPerms;
		}
	}

	/**
	 * @param Permission $permission
	 */
	public function recalculatePermissionDefaults(Permission $permission){
		if(isset($this->permissions[$permission->getName()])){
			unset($this->defaultPermsOp[$permission->getName()]);
			unset($this->defaultPerms[$permission->getName()]);
			$this->calculatePermissionDefault($permission);
		}
	}

	/**
	 * @param Permission $permission
	 */
	private function calculatePermissionDefault(Permission $permission){
		if($permission->getDefault() === Permission::DEFAULT_OP or $permission->getDefault() === Permission::DEFAULT_TRUE){
			$this->defaultPermsOp[$permission->getName()] = $permission;
			$this->dirtyPermissibles(true);
		}

		if($permission->getDefault() === Permission::DEFAULT_NOT_OP or $permission->getDefault() === Permission::DEFAULT_TRUE){
			$this->defaultPerms[$permission->getName()] = $permission;
			$this->dirtyPermissibles(false);
		}
	}

	/**
	 * @param boolean $op
	 */
	private function dirtyPermissibles($op){
		foreach($this->getDefaultPermSubscriptions($op) as $p){
			$p->recalculatePermissions();
		}
	}

	/**
	 * @param string      $permission
	 * @param Permissible $permissible
	 */
	public function subscribeToPermission($permission, Permissible $permissible){
		if(!isset($this->permSubs[$permission])){
			//TODO: Use WeakRef
			$this->permSubs[$permission] = array();
		}
		$this->permSubs[$permission][spl_object_hash($permissible)] = $permissible;
	}

	/**
	 * @param string      $permission
	 * @param Permissible $permissible
	 */
	public function unsubscribeFromPermission($permission, Permissible $permissible){
		if(isset($this->permSubs[$permission])){
			unset($this->permSubs[$permission][spl_object_hash($permissible)]);
		}
	}

	/**
	 * @param string $permission
	 *
	 * @return Permissible[]
	 */
	public function getPermissionSubscriptions($permission){
		if(isset($this->permSubs[$permission])){
			return $this->permSubs[$permission];
		}

		return array();
	}

	/**
	 * @param boolean     $op
	 * @param Permissible $permissible
	 */
	public function subscribeToDefaultPerms($op, Permissible $permissible){
		if($op === true){
			$this->defSubsOp[spl_object_hash($permissible)] = $permissible;
		}else{
			$this->defSubs[spl_object_hash($permissible)] = $permissible;
		}
	}

	/**
	 * @param boolean     $op
	 * @param Permissible $permissible
	 */
	public function unsubscribeFromDefaultPerms($op, Permissible $permissible){
		if($op === true){
			unset($this->defSubsOp[spl_object_hash($permissible)]);
		}else{
			unset($this->defSubs[spl_object_hash($permissible)]);
		}
	}

	/**
	 * @param boolean $op
	 *
	 * @return Permissible[]
	 */
	public function getDefaultPermSubscriptions($op){
		if($op === true){
			return $this->defSubsOp;
		}else{
			return $this->defSubs;
		}
	}

	/**
	 * @return Permission[]
	 */
	public function getPermissions(){
		return $this->permissions;
	}


}