<?php
namespace SimpleAuth\event;
use pocketmine\event\Event;
use pocketmine\Player;
class PlayerUnregisterEvent extends Event{
    private $player;
    public function __construct(Player $player){ $this->player = $player; }
    public function getPlayer(){ return $this->player; }
}
