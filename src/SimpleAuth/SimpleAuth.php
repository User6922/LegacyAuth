<?php
namespace SimpleAuth;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\IPlayer;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\scheduler\Task;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\math\Vector3;
use SimpleAuth\provider\DataProvider;
use SimpleAuth\provider\YamlDataProvider;
use SimpleAuth\event\PlayerAuthenticateEvent;
use SimpleAuth\event\PlayerDeauthenticateEvent;
use SimpleAuth\event\PlayerRegisterEvent;
use SimpleAuth\event\PlayerUnregisterEvent;

class SimpleAuth extends PluginBase implements Listener{
    private $provider;
    private $authenticated = [];
    private $attempts = [];
    private $joinedAt = [];
    private $joinPosition = [];
    private $dataProvider;

    public function onEnable(){
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $this->dataProvider = new YamlDataProvider($this->getDataFolder() . "accounts.yml");
        $this->provider = $this->dataProvider;
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new class($this) extends Task{
            private $plugin;
            public function __construct($plugin){ $this->plugin = $plugin; }
            public function onRun($currentTick){ $this->plugin->checkTimeouts(); }
        }, 20);
        $this->getLogger()->info("SimpleAuth-compatible backend enabled.");
    }

    public function getDataProvider(){ return $this->provider; }
    public function setDataProvider(DataProvider $provider){ $this->provider = $provider; }

    private function key($name){ return strtolower($name); }
    private function passwordHash($name, $password){
        $salt = strtolower($name);
        return bin2hex(hash("sha512", $password . $salt, true) ^ hash("whirlpool", $salt . $password, true));
    }

    public function isPlayerRegistered(IPlayer $player){
        return $this->provider->getPlayerData($player->getName()) !== null;
    }

    public function isPlayerAuthenticated(Player $player){
        return isset($this->authenticated[$this->key($player->getName())]);
    }

    public function registerPlayer(IPlayer $player, $password){
        if($this->isPlayerRegistered($player)){ return false; }
        $min = (int)$this->getConfig()->getNested("settings.min-password-length", 4);
        if(!is_string($password) || strlen($password) < $min){ return false; }
        $name = $player->getName();
        $this->provider->setPlayerData($name, [
            "hash" => $this->passwordHash($name, $password),
            "lastIP" => "",
            "registerDate" => time()
        ]);
        $this->getServer()->getPluginManager()->callEvent(new PlayerRegisterEvent($player));
        return true;
    }

    public function unregisterPlayer(IPlayer $player){
        if(!$this->isPlayerRegistered($player)){ return false; }
        $this->provider->removePlayerData($player->getName());
        if($player instanceof Player){ $this->deauthenticatePlayer($player); }
        if($player instanceof Player){ $this->getServer()->getPluginManager()->callEvent(new PlayerUnregisterEvent($player)); }
        return true;
    }

    public function authenticatePlayer(Player $player){
        if(!$this->isPlayerRegistered($player)){ return false; }
        $key = $this->key($player->getName());
        $this->authenticated[$key] = true;
        unset($this->attempts[$key], $this->joinedAt[$key], $this->joinPosition[$key]);
        $this->getServer()->getPluginManager()->callEvent(new PlayerAuthenticateEvent($player));
        $player->sendMessage($this->getConfig()->getNested("messages.logged-in", "§aSuccessfully logged in."));
        return true;
    }

    public function deauthenticatePlayer(Player $player){
        $key = $this->key($player->getName());
        if(!isset($this->authenticated[$key])){ return false; }
        unset($this->authenticated[$key]);
        $this->getServer()->getPluginManager()->callEvent(new PlayerDeauthenticateEvent($player));
        return true;
    }

    private function isBlocked(Player $player){
        return !$this->isPlayerAuthenticated($player);
    }

    public function checkTimeouts(){
        $timeout = (int)$this->getConfig()->getNested("settings.timeout-seconds", 60);
        if($timeout <= 0){ return; }
        $now = time();
        foreach($this->joinedAt as $key => $joined){
            if(($now - $joined) >= $timeout){
                $player = $this->getServer()->getPlayerExact($key);
                if($player instanceof Player && !$this->isPlayerAuthenticated($player)){
                    $player->kick($this->getConfig()->getNested("messages.timeout", "§cAuthentication timed out."));
                }
                unset($this->joinedAt[$key], $this->joinPosition[$key], $this->attempts[$key]);
            }
        }
    }

    public function onJoin(PlayerJoinEvent $event){
        $p = $event->getPlayer();
        $key = $this->key($p->getName());
        unset($this->authenticated[$key]);
        $this->attempts[$key] = 0;
        $this->joinedAt[$key] = time();
        $this->joinPosition[$key] = new Vector3($p->getX(), $p->getY(), $p->getZ());
        if($this->isPlayerRegistered($p)){
            $p->sendMessage($this->getConfig()->getNested("messages.login", "§ePlease log in with AuthUI."));
        }else{
            $p->sendMessage($this->getConfig()->getNested("messages.register", "§ePlease register with AuthUI."));
        }
    }

    public function onQuit(PlayerQuitEvent $event){
        $key = $this->key($event->getPlayer()->getName());
        unset($this->authenticated[$key], $this->attempts[$key], $this->joinedAt[$key], $this->joinPosition[$key]);
    }

    public function onMove(PlayerMoveEvent $event){
        $p = $event->getPlayer();
        if(!$this->isBlocked($p)){ return; }
        if($this->getConfig()->getNested("settings.allow-movement-before-login", false)){ return; }
        $from = $event->getFrom();
        $to = $event->getTo();
        if($to !== null && ($from->getX() != $to->getX() || $from->getY() != $to->getY() || $from->getZ() != $to->getZ())){
            $event->setCancelled(true);
        }
    }

    public function onCommandPreprocess(PlayerCommandPreprocessEvent $event){
        $p = $event->getPlayer();
        if(!$this->isBlocked($p)){ return; }
        $message = trim($event->getMessage());
        $parts = preg_split("/\s+/", ltrim($message, "/"));
        $command = strtolower(isset($parts[0]) ? $parts[0] : "");
        if($command !== "login" && $command !== "register" && $command !== "unregister") {
            $event->setCancelled(true);
            $p->sendMessage("§cPlease authenticate first.");
        }
    }

    public function onChat(PlayerChatEvent $event){
        if($this->isBlocked($event->getPlayer()) && !$this->getConfig()->getNested("settings.allow-chat-before-login", false)){
            $event->setCancelled(true);
            $event->getPlayer()->sendMessage("§cPlease authenticate first.");
        }
    }

    public function onInteract(PlayerInteractEvent $event){
        if($this->isBlocked($event->getPlayer())){ $event->setCancelled(true); }
    }
    public function onBreak(BlockBreakEvent $event){ if($this->isBlocked($event->getPlayer())){ $event->setCancelled(true); } }
    public function onPlace(BlockPlaceEvent $event){ if($this->isBlocked($event->getPlayer())){ $event->setCancelled(true); } }
    public function onDrop(PlayerDropItemEvent $event){ if($this->isBlocked($event->getPlayer())){ $event->setCancelled(true); } }
    public function onDamage(EntityDamageEvent $event){ if($event->getEntity() instanceof Player && $this->isBlocked($event->getEntity())){ $event->setCancelled(true); } }
    public function onInventory(InventoryTransactionEvent $event){ if($event->getTransaction()->getSource() instanceof Player && $this->isBlocked($event->getTransaction()->getSource())){ $event->setCancelled(true); } }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        $name = strtolower($command->getName());
        if(!$sender instanceof Player){ return true; }
        if($name === "login"){
            if($this->isPlayerAuthenticated($sender)){ $sender->sendMessage("§aAlready logged in."); return true; }
            if(!$this->isPlayerRegistered($sender)){ $sender->sendMessage($this->getConfig()->getNested("messages.not-registered")); return true; }
            if(!isset($args[0])){ $sender->sendMessage("§cUsage: /login <password>"); return true; }
            $data = $this->provider->getPlayerData($sender->getName());
            if($data !== null && isset($data["hash"]) && hash_equals($data["hash"], $this->passwordHash($sender->getName(), $args[0]))){
                $this->authenticatePlayer($sender);
            }else{
                $key = $this->key($sender->getName());
                $this->attempts[$key] = isset($this->attempts[$key]) ? $this->attempts[$key] + 1 : 1;
                $max = (int)$this->getConfig()->getNested("settings.max-login-attempts", 5);
                if($this->attempts[$key] >= $max){ $sender->kick($this->getConfig()->getNested("messages.max-attempts")); }
                else { $sender->sendMessage($this->getConfig()->getNested("messages.wrong-password")); }
            }
            return true;
        }
        if($name === "register"){
            if($this->isPlayerRegistered($sender)){ $sender->sendMessage($this->getConfig()->getNested("messages.already-registered")); return true; }
            if(!isset($args[0])){ $sender->sendMessage("§cUsage: /register <password>"); return true; }
            if($this->registerPlayer($sender, $args[0])){ $sender->sendMessage($this->getConfig()->getNested("messages.registered")); $this->authenticatePlayer($sender); }
            else { $sender->sendMessage($this->getConfig()->getNested("messages.invalid-password")); }
            return true;
        }
        if($name === "unregister"){
            if(!$this->isPlayerAuthenticated($sender)){ $sender->sendMessage("§cPlease authenticate first."); return true; }
            if(!isset($args[0])){ $sender->sendMessage("§cUsage: /unregister <password>"); return true; }
            $data = $this->provider->getPlayerData($sender->getName());
            if($data !== null && isset($data["hash"]) && hash_equals($data["hash"], $this->passwordHash($sender->getName(), $args[0]))){
                $this->unregisterPlayer($sender); $sender->sendMessage($this->getConfig()->getNested("messages.unregistered"));
            }else{ $sender->sendMessage($this->getConfig()->getNested("messages.wrong-password")); }
            return true;
        }
        return true;
    }
}
