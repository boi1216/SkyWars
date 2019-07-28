<?php

namespace SkyWars;


use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\math\Vector3;
use SkyWars\npc\NPCEntity;

class SkyWars extends PluginBase
{

    /** @var Arena[] $arenas */
    public $arenas = [];

    /** @var array $settings */
    private $settings;

    /** @var array $playerArenas */
    private $playerArenas = [];

    /** @var Entity $NPCEntity */
    private $NPCEntity;

    /** @var SkyWars $instance */
    public static $instance;

    public function onEnable() : void
    {

        foreach ($this->getResources() as $resource) {
            $this->saveResource($resource->getFilename());
        }

        $this->settings = yaml_parse_file($this->getDataFolder() . "skywars_settings.yml");
        $this->loadArenas();

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        $this->getScheduler()->scheduleRepeatingTask(new ArenaScheduler($this), 20);

        Entity::registerEntity(NPCEntity::class, true);

        self::$instance = $this;



    }

    /**
     * @param Player $player
     */
    public function spawnNPC(Player $player) : void{
        $nbt = Entity::createBaseNBT($player, null, $player->getYaw(), $player->getPitch());
        $skinTag = $player->namedtag->getCompoundTag("Skin");
        assert($skinTag !== null);
        $nbt->setTag(clone $skinTag);

        $nametag = TextFormat::BOLD . TextFormat::AQUA . "SkyWars" . TextFormat::RESET . "\n".
                   TextFormat::YELLOW . "0 Playing";

        $entity = Entity::createEntity("NPCEntity", $player->getLevel(), $nbt);
        $entity->setNameTag($nametag);
        $entity->spawnToAll();
    }

    public function loadArenas() : void{
        $base_path = $this->getDataFolder() . "arenas/";
        @mkdir($base_path);

        foreach (scandir($base_path) as $dir) {
            $dir = $base_path . $dir;
            $settings_path = $dir . "/settings.yml";

            if (!is_file($settings_path)) {
                continue;
            }

            $arena_info = yaml_parse_file($settings_path);

            $this->arenas[$arena_info["name"]] = new Arena(
                $this,
                (string) $arena_info["name"],
                (int) $arena_info["slot"],
                (string) $arena_info["world"],
                (string) $arena_info['gameType'],
                (int) $arena_info["void_Y"]
            );
        }
    }

    /**
     * @return array
     */
    public function getSettings() : array{
        return $this->settings;
    }

    /**
     * @param Player $player
     * @param null|string $arena
     */
    public function setPlayerArena(Player $player, ?string $arena) : void{
        if(is_null($arena)){
            unset($this->playerArenas[$player->getName()]);
            return;
        }

        $this->playerArenas[$player->getName()] = $arena;
    }

    /**
     * @param Player $player
     * @return null|Arena
     */
    public function getPlayerArena(Player $player) : ?Arena{
        return isset($this->playerArenas[$player->getName()]) ? $this->arenas[$this->playerArenas[$player->getName()]] : null;
    }

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     * @throws \InvalidStateException
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if(strtolower($command->getName()) == "skywars"){
            if(empty($args[0]) || !$sender->isOp())return false;
            switch($args[0]){
                case "create";
                if(!$sender instanceof Player){
                    $sender->sendMessage(TextFormat::RED . "This command can be executed only in game!");
                    return false;
                }


                if(count($args) < 2){
                    $sender->sendMessage(TextFormat::AQUA . "Usage: " . TextFormat::WHITE . "/skywars create [name] [slots] [type]");
                    return false;
                }

                $arenaName = $args[1];
                $slots = intval($args[2]);
                $arenaType = $args[3];

                if($arenaType !== "solo" && $arenaType !== "team"){
                    $sender->sendMessage(TextFormat::RED . "Invalid arena type");
                    return false;
                }

                $level = $sender->getLevel();
                $levelName = $sender->getLevel()->getFolderName();

                if($this->getServer()->getDefaultLevel()->getFolderName() == $levelName){
                    $sender->sendMessage(TextFormat::RED . "You can't create arenas in default level!");
                    return false;
                }

                foreach($this->arenas as $arena => $arenaInstance){
                    if($arenaInstance->getWorldName() == $levelName){
                        $sender->sendMessage(TextFormat::RED . "There's already arena in this level!");
                        return false;
                    }
                }

                $sender->sendMessage(TextFormat::LIGHT_PURPLE . "Calculating minimum void in world '" . $levelName . "'...");

                $void_y = Level::Y_MAX;
                foreach ($level->getChunks() as $chunk) {
                    for ($x = 0; $x < 16; ++$x) {
                        for ($z = 0; $z < 16; ++$z) {
                            for ($y = 0; $y < $void_y; ++$y) {
                                $block = $chunk->getBlockId($x, $y, $z);
                                if ($block !== Block::AIR) {
                                    $void_y = $y;
                                    break;
                                }
                                }
                            }
                        }
                    }
                --$void_y;

                $sender->sendMessage(TextFormat::LIGHT_PURPLE . "Minimum void set to: " . $void_y);

                $sender->teleport($this->getServer()->getDefaultLevel()->getSpawnLocation());
                $this->getServer()->unloadLevel($level);
                unset($level);

                @mkdir($this->getDataFolder() . "arenas/" . $arenaName, 0755);
                $tar = new \PharData($this->getDataFolder() . "arenas/" . $arenaName . "/" . $levelName . ".tar");
                $tar->startBuffering();
                $tar->buildFromDirectory(realpath($sender->getServer()->getDataPath() . "worlds/" . $levelName));
                $tar->stopBuffering();

                $sender->sendMessage(TextFormat::LIGHT_PURPLE . "Backup of world '" . $levelName . "' created.");
                $this->getServer()->loadLevel($levelName);

                $this->arenas[$arenaName] = new Arena($this, $arenaName, $slots, $levelName, $arenaType, $void_y);
                $sender->sendMessage(TextFormat::GREEN . "Arena created");
                break;
                case "setspawn";
                if(!$sender instanceof Player){
                    $sender->sendMessage(TextFormat::RED . "This command can be executed only in game!");
                    return false;
                }
                if(empty($args[1])){
                    $sender->sendMessage(TextFormat::AQUA . "Usage: " . TextFormat::WHITE . "/skywars setspawn [spawn]");
                }
                $levelName = $sender->getLevel()->getFolderName();

                $arena = null;
                foreach($this->arenas as $name => $arenaInstance){
                    if($arenaInstance->getWorldName() == $levelName){
                        $arena = $arenaInstance;
                    }
                }

                if(is_null($arena)){
                    $sender->sendMessage(TextFormat::RED . "Arena not found in this level!");
                    return false;
                }

                $arena->setSpawn($sender, intval($args[1]));
                break;
                case "createnpc";
                $this->spawnNPC($sender);
                break;
            }
        }
        return false;
    }

    /**
     * @return null|Entity
     */
    public function getNPCEntity() : ?Entity{
        $level = $this->getServer()->getDefaultLevel();
        if(is_null($this->NPCEntity)) {
            foreach ($level->getEntities() as $entity) {
                if ($entity instanceof Player) return null;
                $name = explode("\n", $entity->getNameTag());
                if ($name[0] == TextFormat::GREEN . "SkyWars") {
                    $this->NPCEntity = $entity;
                    return $entity;
                }
            }
        }else{
            return $this->NPCEntity;
        }
        return null;
    }

    /**
     * @return Arena
     */
    public function findBestArena() : ?Arena{
        $newArenas = [];
        foreach($this->arenas as $arena){
            if($arena->GAME_STATE !== Arena::STATE_COUNTDOWN)continue;
            $newArenas[] = $arena;

        }
        $max = $this->max_attribute_in_array($newArenas);
        foreach($newArenas as $arena){
            if(count($arena->players) == $max){
                return $arena;
            }
        }
        return null;
    }

    /**
     * @param $data_points
     * @param string $value
     * @return int
     */
    public function max_attribute_in_array($data_points, $value='players') : int{
        $max=0;
        foreach($data_points as $point){
            if($max < (float)count($point->{$value})){
                $max = count($point->{$value});
            }
        }
        return $max;
    }

    public function getChestContents() : array//TODO: **rewrite** this and let the owner decide the contents of the chest
    {
        $items = [
            //ARMOR
            "armor" => [
                [
                    Item::LEATHER_CAP,
                    Item::LEATHER_TUNIC,
                    Item::LEATHER_PANTS,
                    Item::LEATHER_BOOTS
                ],
                [
                    Item::GOLD_HELMET,
                    Item::GOLD_CHESTPLATE,
                    Item::GOLD_LEGGINGS,
                    Item::GOLD_BOOTS
                ],
                [
                    Item::CHAIN_HELMET,
                    Item::CHAIN_CHESTPLATE,
                    Item::CHAIN_LEGGINGS,
                    Item::CHAIN_BOOTS
                ],
                [
                    Item::IRON_HELMET,
                    Item::IRON_CHESTPLATE,
                    Item::IRON_LEGGINGS,
                    Item::IRON_BOOTS
                ],
                [
                    Item::DIAMOND_HELMET,
                    Item::DIAMOND_CHESTPLATE,
                    Item::DIAMOND_LEGGINGS,
                    Item::DIAMOND_BOOTS
                ]
            ],

            //WEAPONS
            "weapon" => [
                [
                    Item::WOODEN_SWORD,
                    Item::WOODEN_AXE,
                ],
                [
                    Item::GOLD_SWORD,
                    Item::GOLD_AXE
                ],
                [
                    Item::STONE_SWORD,
                    Item::STONE_AXE
                ],
                [
                    Item::IRON_SWORD,
                    Item::IRON_AXE
                ],
                [
                    Item::DIAMOND_SWORD,
                    Item::DIAMOND_AXE
                ]
            ],

            //FOOD
            "food" => [
                [
                    Item::RAW_PORKCHOP,
                    Item::RAW_CHICKEN,
                    Item::MELON_SLICE,
                    Item::COOKIE
                ],
                [
                    Item::RAW_BEEF,
                    Item::CARROT
                ],
                [
                    Item::APPLE,
                    Item::GOLDEN_APPLE
                ],
                [
                    Item::BEETROOT_SOUP,
                    Item::BREAD,
                    Item::BAKED_POTATO
                ],
                [
                    Item::MUSHROOM_STEW,
                    Item::COOKED_CHICKEN
                ],
                [
                    Item::COOKED_PORKCHOP,
                    Item::STEAK,
                    Item::PUMPKIN_PIE
                ],
            ],

            //THROWABLE
            "throwable" => [
                [
                    Item::BOW,
                    Item::ARROW
                ],
                [
                    Item::SNOWBALL
                ],
                [
                    Item::EGG
                ]
            ],

            //BLOCKS
            "block" => [
                Item::STONE,
                Item::WOODEN_PLANKS,
                Item::COBBLESTONE,
                Item::DIRT
            ],

            //OTHER
            "other" => [
                [
                    Item::WOODEN_PICKAXE,
                    Item::GOLD_PICKAXE,
                    Item::STONE_PICKAXE,
                    Item::IRON_PICKAXE,
                    Item::DIAMOND_PICKAXE
                ],
                [
                    Item::STICK,
                    Item::STRING
                ]
            ]
        ];

        $templates = [];
        for ($i = 0; $i < 10; $i++) {//TODO: understand wtf is the stuff in here doing

            $armorq = mt_rand(0, 1);
            $armortype = $items["armor"][array_rand($items["armor"])];

            $armor1 = [$armortype[array_rand($armortype)], 1];
            if ($armorq) {
                $armortype = $items["armor"][array_rand($items["armor"])];
                $armor2 = [$armortype[array_rand($armortype)], 1];
            } else {
                $armor2 = [0, 1];
            }

            $weapontype = $items["weapon"][array_rand($items["weapon"])];
            $weapon = [$weapontype[array_rand($weapontype)], 1];

            $ftype = $items["food"][array_rand($items["food"])];
            $food = [$ftype[array_rand($ftype)], mt_rand(2, 5)];

            if (mt_rand(0, 1)) {
                $tr = $items["throwable"][array_rand($items["throwable"])];
                if (count($tr) === 2) {
                    $throwable1 = [$tr[1], mt_rand(10, 20)];
                    $throwable2 = [$tr[0], 1];
                } else {
                    $throwable1 = [0, 1];
                    $throwable2 = [$tr[0], mt_rand(5, 10)];
                }
                $other = [0, 1];
            } else {
                $throwable1 = [0, 1];
                $throwable2 = [0, 1];
                $ot = $items["other"][array_rand($items["other"])];
                $other = [$ot[array_rand($ot)], 1];
            }

            $block = [$items["block"][array_rand($items["block"])], 64];

            $contents = [
                $armor1,
                $armor2,
                $weapon,
                $food,
                $throwable1,
                $throwable2,
                $block,
                $other
            ];
            shuffle($contents);

            $fcontents = [
                mt_rand(0, 1) => array_shift($contents),
                mt_rand(2, 4) => array_shift($contents),
                mt_rand(5, 9) => array_shift($contents),
                mt_rand(10, 14) => array_shift($contents),
                mt_rand(15, 16) => array_shift($contents),
                mt_rand(17, 19) => array_shift($contents),
                mt_rand(20, 24) => array_shift($contents),
                mt_rand(25, 26) => array_shift($contents),
            ];

            $templates[] = $fcontents;
        }

        shuffle($templates);
        return $templates;
    }

    /**
     * @param Arena $arena
     * @param Player $player
     * @param int $forceCause
     */
    public function sendDeathMessage(Arena $arena, Player $player, int $forceCause = null) : void{
        $lastCause = $player->getLastDamageCause();
        switch($forceCause == null ? $lastCause->getCause() : $forceCause){
            case EntityDamageEvent::CAUSE_ENTITY_ATTACK;
            $killer = $lastCause->getDamager();
            $arena->broadcastMessage(TextFormat::GREEN . $player->getName() . " " . TextFormat::AQUA . "was killed by " . TextFormat::RED . $killer->getName() . " " . TextFormat::GREEN . "(" . count($arena->getAlivePlayers()) . "/" . $arena->slots . ")");
            break;
            case EntityDamageEvent::CAUSE_VOID;
            $arena->broadcastMessage(TextFormat::GREEN . $player->getName() . " " . TextFormat::AQUA . "was killed by  " . TextFormat::RED . "Void " . TextFormat::GREEN . "(" . count($arena->getAlivePlayers()) . "/" . $arena->slots . ")");
            break;
        }
    }

    /**
     * @param Arena $arena
     * @return string
     */
    public function getBoardFormat(Arena $arena) : string{
        $format =  [
            0 => TextFormat::BOLD . TextFormat::YELLOW . "SkyWars\n" . TextFormat::RESET.
                 TextFormat::AQUA . "Players: " . TextFormat::GREEN . count($arena->players) . "\n".
                 TextFormat::AQUA . "Start: " . TextFormat::GREEN . gmdate("i:s", $arena->startTime),
            1 => TextFormat::BOLD . TextFormat::YELLOW . "SkyWars\n" . TextFormat::RESET.
                 TextFormat::AQUA . "Players: " . TextFormat::GREEN . count($arena->getAlivePlayers()) . "\n".
                 TextFormat::AQUA . "Chest refill: " . TextFormat::GREEN . gmdate("i:s", round(($arena->getLastRefill() + 240) - time()))];
        return $format[$arena->GAME_STATE];
    }


}