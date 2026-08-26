<?php
namespace SimpleAuth\provider;

use pocketmine\utils\Config;

class YamlDataProvider implements DataProvider{
    private $config;

    public function __construct($file){
        $this->config = new Config($file, Config::YAML, []);
    }

    private function key($name){
        return strtolower($name);
    }

    public function getPlayerData($name){
        $data = $this->config->get($this->key($name), null);
        return is_array($data) ? $data : null;
    }

    public function setPlayerData($name, array $data){
        $this->config->set($this->key($name), $data);
        $this->config->save();
    }

    public function removePlayerData($name){
        $this->config->remove($this->key($name));
        $this->config->save();
    }
}
