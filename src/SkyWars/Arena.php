<?php

namespace SkyWars;


use pocketmine\entity\effect\Effect;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\item\Armor;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Item;
use pocketmine\item\Sword;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\tile\Chest;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;
use SkyWars\scoreboard\Scoreboard;

class Arena
{

    /** @var SkyWars $plugin */
    private $plugin;

    /** @var string $name */
    private $name;

    /** @var int $slots */
    public $slots;

    /** @var string $worldName */
    private $worldName;

    /** @var string $type */
    private $type;

    /** @var int $void */
    public $void;

    /** @var array $spawns */
    private $spawns = [];

    /** @var array $players */
    public $players = [];

    /** @var array $spectators */
    public $spectators = [];

    /** @var array $playerSpawns */
    private $playerSpawns = [];

    const STATE_COUNTDOWN = 0;
    const STATE_RUNNING = 1;

    /** @var int $GAME_STATE */
    public $GAME_STATE = self::STATE_COUNTDOWN;

    /** @var array $teams */
    private $teams = [];

    /** @var int $playersPerTeam */
    private $playersPerTeam = 2;

    /** @var int $requiredPlayers */
    private $requiredPlayers = 2;

    /** @var bool $starting */
    private $starting = false;

    /** @var int $startTime */
    public $startTime = 60;

    const TEAM_LIST = [
        'RED' => "§c",
        "GREEN" => "§a",
        "AQUA" => "§b",
        "YELLOW" => "§e",
        "BLUE" => "§9",
        "PINK" => "§b"
    ];

    /** @var int $time */
    private $time;

    /** @var int $lastRefill */
    private $lastRefill;


    /**
     * Arena constructor.
     * @param SkyWars $plugin
     * @param string $name
     * @param int $slots
     * @param string $worldName
     * @param string $type
     * @param int $void
     */
    public function __construct(SkyWars $plugin, string $name, int $slots = 0, string $worldName, string $type, int $void){
        $this->plugin = $plugin;
        $this->name = $name;
        $this->slots = $slots;
        $this->worldName = $worldName;
        $this->type = $type;
        $this->void = $void;


        if (!$this->reload($error)) {
            $logger = $this->plugin->getLogger();
            $logger->error("An error occured while reloading the arena: " . TextFormat::YELLOW . $this->SWname);
            $logger->error($error);
            $this->plugin->getServer()->getPluginManager()->disablePlugin($this->plugin);
        }

        if($this->type == "team"){
            $this->prepareTeams();
            $this->requiredPlayers = 4;

        }

    }

    /**
     * TEAM MODE ONLY
     */
    public function prepareTeams() : void{
        $teamCount = $this->slots / 2;
        $teams = [
            'RED' => "§c",
            "GREEN" => "§a",
            "AQUA" => "§b",
            "YELLOW" => "§e",
            "BLUE" => "§9",
            "PINK" => "§b"
        ];

        foreach(range(0, $teamCount - 1) as $int){
            echo $int . PHP_EOL;
            $teamColor = array_values($teams)[$int];
            $teamName = array_keys($teams)[$int];
            $this->teams[$int] = new Team($teamName, $teamColor);
        }

    }

    /**
     * @param Player $player
     */
    public function selectTeam(Player $player) : void{
        $top = $this->plugin->max_attribute_in_array($this->teams);
        foreach($this->teams as $team){
            if(count($team->getPlayers()) == $top && count($team->getPlayers()) !== $this->playersPerTeam){
                $team->addPlayer($player);
                return;
            }else{
                if(count($team->getPlayers()) !== $this->playersPerTeam){
                    $team->addPlayer($player);
                    return;
                }
            }
        }
    }

    /**
     * @return string
     */
    public function getType() : string{
        return $this->type;
    }

    /**
     * @return int
     */
    public function getLastRefill() : int{
        return $this->lastRefill;
    }

    /**
     * @return string
     */
    public function getName() : string{
        return $this->name;
    }

    /**
     * @param Player $player
     * @param int $spawn
     * @throws \InvalidStateException
     */
    public function setSpawn(Player $player, int $spawn) : void{
        if($spawn > $this->slots){
            $player->sendMessage(TextFormat::RED . "This arena got only " . $this->slots . " spawns available");
            return;
        }

        $config = new Config($this->plugin->getDataFolder() . "arenas/" . $this->name . "/settings.yml", Config::YAML);
        if (empty($config->get("spawns", []))) {
            $config->set("spawns", array_fill(1, $this->type == "team" ? $this->slots / 2 : $this->slots, [
                "x" => "n.a",
                "y" => "n.a",
                "z" => "n.a",
                "yaw" => "n.a",
                "pitch" => "n.a"
            ]));
        }
        $s = $config->get("spawns");
        $s[$spawn] = [
            "x" => floor($player->x),
            "y" => floor($player->y),
            "z" => floor($player->z),
            "yaw" => $player->yaw,
            "pitch" => $player->pitch
        ];

        $config->set("spawns", $s);
        $config->save();
        $this->spawns = $s;

        $player->sendMessage(TextFormat::GREEN . "Spawn " . TextFormat::YELLOW . $spawn . TextFormat::GREEN . " has been set");
    }

    /**
     * @param null $error
     * @return bool
     */
    private function reload(&$error = null) : bool
    {
        //Map reset
        if (!is_file($file = $this->plugin->getDataFolder() . "arenas/" . $this->name . "/" . $this->worldName . ".tar") && !is_file($file = $this->plugin->getDataFolder() . "arenas/" . $this->name . "/" . $this->worldName . ".tar.gz")) {
            $error = "Cannot find world backup file $file";
            return false;
        }

        $server = $this->plugin->getServer();

        if ($server->isLevelLoaded($this->worldName)) {
            $server->unloadLevel($server->getLevelByName($this->worldName));
        }

        $tar = new \PharData($file);
        $tar->extractTo($server->getDataPath() . "worlds/" . $this->worldName, null, true);

        $server->loadLevel($this->worldName);
        $server->getLevelByName($this->worldName)->setAutoSave(false);

        $config = new Config($this->plugin->getDataFolder() . "arenas/" . $this->name . "/settings.yml", Config::YAML, [//TODO: put descriptions
            "name" => $this->name,
            "slot" => $this->slots,
            "world" => $this->worldName,
            "gameType" => $this->type,
            "void_Y" => $this->void,
            "spawns" => []
        ]);

        $this->name = $config->get("name");
        $this->slots = (int) $config->get("slot");
        $this->worldName = $config->get("world");
        $this->type = $config->get('gameType');
        $this->spawns = $config->get("spawns");
        $this->void = (int) $config->get("void_Y");

        $this->players = [];
        $this->GAME_STATE = self::STATE_COUNTDOWN;

        $this->time = 0;
        $this->lastRefill = 0;
        return true;
    }

    /**
     * @return string
     */
    public function getWorldName() : string{
        return $this->worldName;
    }

    /**
     * @param Player $player
     */
    public function join(Player $player) : void{
        if($this->GAME_STATE == self::STATE_RUNNING){
            $player->sendMessage(TextFormat::RED . "Sorry, this game is already running!");
            return;
        }

        if(count($this->players) >= $this->slots || empty($this->slots)){
            $player->sendMessage(TextFormat::RED . "Sorry, this game is full!");
            return;
        }

        $player->setHealth($player->getMaxHealth());
        $player->setFood($player->getMaxFood());
        $player->removeAllEffects();
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();

        $server = $this->plugin->getServer();
        $level = $server->getLevelByName($this->worldName);

        $tmp = null;
        if($this->type == "solo") {
            $tmp = array_shift($this->spawns);
            $player->teleport(new Position($tmp["x"] + 0.5, $tmp["y"], $tmp["z"] + 0.5, $level), $tmp["yaw"], $tmp["pitch"]);
        }else{
            $this->selectTeam($player);
            $playerTeam = null;
            foreach($this->teams as $team){
                if(in_array($player->getName(), array_keys($team->players))){
                    $playerTeam = $team;
                }
            }
            $teamSpawn = array_search($playerTeam->getName(), array_keys(self::TEAM_LIST));
            $tmp = $this->spawns[$teamSpawn + 1];
            $player->teleport(new Position($tmp["x"] + 0.5, $tmp["y"], $tmp["z"] + 0.5, $level), $tmp["yaw"], $tmp["pitch"]);
            $player->sendMessage(TextFormat::GRAY . "You've joined team " . $playerTeam->getColor() . $playerTeam->getName());

        }
        $this->playerSpawns[$player->getRawUniqueId()] = $tmp;
        $this->plugin->setPlayerArena($player, $this->getName());
        $this->players[$player->getName()] = $player;
        $player->setImmobile(true);

        $this->broadcastMessage(TextFormat::GREEN . $player->getName() . " " . TextFormat::AQUA . "has joined the game " . TextFormat::YELLOW . "(" . count($this->players) . "/" . $this->slots . ")");

        Scoreboard::sendBoard($player, $this->plugin->getBoardFormat($this), "skywars");

    }

    /**
     * @param Player $player
     * @return bool
     */
    public function inArena(Player $player) : bool{
        return isset($this->players[$player->getName()]);
    }

    /**
     * @param string $message
     */
    public function broadcastMessage(string $message) : void{
        foreach($this->players as $player){
            $player->sendMessage($message);
        }
    }

    public function start() : void{
        $this->starting = false;
        $this->broadcastMessage(TextFormat::GREEN . "Game has started!");

        foreach($this->players as $player){
            $player->setImmobile(false);
        }
        $this->GAME_STATE = self::STATE_RUNNING;
        $this->time = 0;

        $this->refillChests();
    }

    /**
     * @return array
     */
    public function getAlivePlayers() : array{
        $alivePlayers = [];
        foreach($this->players as $player){
            if(isset($this->spectators[$player->getName()]))continue;
            $alivePlayers[$player->getName()] = $player;
        }
        return $alivePlayers;
    }

    /**
     * @param Player $player
     */
    public function killPlayer(Player $player) : void{
        if((count($this->getAlivePlayers()) - 1) > 1){
            $player->addTitle(TextFormat::BOLD . TextFormat::AQUA . "You lost!", TextFormat::YELLOW . "You are now spectating");
            $player->setGamemode(Player::SURVIVAL);
            $player->teleport(new Vector3($player->x, $this->void + 15, $player->z));
            $player->setFlying(true);
            foreach($this->getAlivePlayers() as $p){
                if(!$player instanceof Player)return;
                $p->hidePlayer($player);
            }
        }else{
            $this->spectators[$player->getName()] = $player;
            $player->teleport($this->plugin->getServer()->getDefaultLevel()->getSafeSpawn());
            Scoreboard::removeBoard($player, "skywars");
        }


    }

    /**
     * @param bool $force
     */
    public function stop(bool $force = false) : void{
        foreach($this->players as $player){
            $is_winner = !$force && in_array($player->getName(), array_keys($this->getAlivePlayers()));
            $this->removePlayer($player);

            if($is_winner){
                $this->plugin->getServer()->broadcastMessage(TextFormat::GREEN . $player->getName() . " " . TextFormat::AQUA . "has won SurvivalGames game on arena " . TextFormat::GREEN . $this->getName());
            }
        }


        $this->reload();
    }


    /**
     * @param Player $player
     */
    public function removePlayer(Player $player, bool $left = false, bool $spectate = false) : void{
        if($this->quit($player, $left, $spectate)){
            $player->setGamemode($this->plugin->getServer()->getDefaultGamemode());
            if(!$spectate){
                $player->setHealth(20);
                $player->setFood(20);
                $player->teleport($this->plugin->getServer()->getDefaultLevel()->getSafeSpawn());
            }elseif($this->GAME_STATE !== self::STATE_COUNTDOWN && count($this->getAlivePlayers()) > 1){
                $player->setGamemode(Player::SPECTATOR);
                foreach($this->getAlivePlayers() as $p){
                    $p->hidePlayer($player);
                }
                $player->addTitle(TextFormat::AQUA . "You lost!", TextFormat::YELLOW . "Type /hub to quit");
            }
        }
    }

    /**
     * @param Player $player
     * @param bool $left
     * @param bool $spectate
     * @return bool
     */
    public function quit(Player $player, bool $left = false, bool $spectate = false) : bool{
        if($this->GAME_STATE == self::STATE_COUNTDOWN){
            $player->setImmobile(false);
            $this->spawns[] = $this->playerSpawns[$uuid = $player->getRawUniqueId()];
            unset($this->playerSpawns[$uuid]);
        }

        if(isset($this->spectators[$player->getName()]) && $this->GAME_STATE == self::STATE_RUNNING){
            foreach($this->players as $pl){
                $pl->showPlayer($player);
            }
        }

        $this->plugin->setPlayerArena($player, null);

        if($left){
            unset($this->players[$player->getName()]);
            $this->broadcastMessage(TextFormat::AQUA . $player->getName() . " " . TextFormat::YELLOW . "has left the game " . TextFormat::GREEN . "(" . count($this->getAlivePlayers()) . "/" . $this->slots . ")");
        }

        if($spectate && !isset($this->spectators[$player->getName()])){
            $this->spectators[$player->getName()] = $player;
            foreach($this->spectators as $spectator){
                $spectator->showPlayer($player);
            }
        }else{
            Scoreboard::removeBoard($player, "skywars");
        }
        return true;
    }

    public function refillChests() : void{
        $level = $this->plugin->getServer()->getLevelByName($this->getWorldName());
        if(!$level instanceof Level)return;
        $contents = $this->plugin->getChestContents();
        foreach($level->getTiles() as $tile){
            if($tile instanceof Chest){
                $inventory = $tile->getInventory();
                $inventory->clearAll();

                if (empty($contents)) {
                    $contents = $this->plugin->getChestContents();
                }

                foreach (array_shift($contents) as $key => $val) {
                    $item = Item::get($val[0], 0, $val[1]);
                    $item = $this->enchantItem($item);
                    $inventory->setItem($key,$item , false);
                }

                $inventory->sendContents($inventory->getViewers());
            }
        }
        $this->lastRefill = time();
    }

    /**
     * @param $item
     * @return Item
     */
    public function enchantItem($item) : Item{
        $armorEnchantments = [
            Enchantment::PROTECTION => 4,
            Enchantment::FIRE_PROTECTION => 4,
            Enchantment::THORNS => 4
        ];

        $swordEnchantments = [
            Enchantment::FIRE_ASPECT => 2,
            Enchantment::SHARPNESS => 5,
            Enchantment::KNOCKBACK => 2
        ];

        a:
        if($item instanceof Armor){
            $enchantment = array_rand($armorEnchantments);
            if($b = rand(1,2) == 2){
                $item->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment($enchantment), $armorEnchantments[$enchantment]));
            }
            if($b == 2 && rand(1,2) == 2){
                goto a;//second enchantment
            }
        }
        if($item instanceof Sword){
            $enchantment = array_rand($swordEnchantments);
            if($b = rand(1,2) == 2){
                $item->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment($enchantment), $swordEnchantments[$enchantment]));
            }
            if($b == 2 && rand(1,2) == 2){
                goto a;//second enchantment
            }
        }
        return $item;
    }

    private $waitingDotState = 1;

    public function tick() : void{
        switch($this->GAME_STATE){
            case self::STATE_COUNTDOWN;
            if(count($this->players) < $this->requiredPlayers){
                foreach($this->players as $player){
                    $player->sendTip(TextFormat::YELLOW . "Waiting for players (" . TextFormat::AQUA . ($this->requiredPlayers - count($this->players)) . TextFormat::YELLOW . ")");
                    $this->waitingDotState++;
                    if($this->waitingDotState > 3)$this->waitingDotState = 1;
                }
            }else{
                if(!$this->starting){
                    $this->starting = true;
                    $this->broadcastMessage(TextFormat::GREEN . "Countdown started!");
                }
            }

            if($this->starting){
                $this->startTime--;
                foreach($this->players as $player){
                    $player->sendTip(TextFormat::AQUA . "Starting in " . TextFormat::YELLOW . gmdate("i:s", $this->startTime));
                }
                if(count($this->players) >= $this->slots - 2 && $this->startTime < 15){
                    $this->startTime = 15;
                }
                if($this->startTime == 0){
                     $this->start();
                }
            }
            break;
            case self::STATE_RUNNING;
            $playerCount = count($this->getAlivePlayers());
            if($playerCount < 2){
                $this->stop();
            }

            if($this->time % 240 == 0){
               $this->refillChests();
               foreach($this->players as $player){
                   $player->addTitle(TextFormat::GREEN . "Chests has been refilled!");
               }
            }
            break;
        }
        foreach($this->players as $player){
            Scoreboard::renameBoard("skywars", $this->plugin->getBoardFormat($this), $player);
        }
        $this->time++;
    }

}