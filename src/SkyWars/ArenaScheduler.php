<?php

namespace SkyWars;


use pocketmine\entity\Human;
use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat;

class ArenaScheduler extends Task
{

    /** @var SkyWars $plugin */
    private $plugin;

    const NPC_PREFIX = TextFormat::BOLD.TextFormat::AQUA."TAP TO JOIN";

    /**
     * ArenaScheduler constructor.
     * @param SkyWars $plugin
     */
    public function __construct(SkyWars $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @param int $currentTick
     */
    public function onRun(int $currentTick)
    {
         foreach($this->plugin->arenas as $arena){
             $arena->tick();
         }

    }

}