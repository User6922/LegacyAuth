<?php
namespace SimpleAuth\provider;

interface DataProvider{
    public function getPlayerData($name);
    public function setPlayerData($name, array $data);
    public function removePlayerData($name);
}
