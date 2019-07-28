<?php

namespace SkyWars;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use SkyWars\npc\NPCEntity;

class EventListener implements Listener
{

    /** @var SkyWars $plugin */
    private $plugin;

    /**
     * EventListener constructor.
     * @param SkyWars $plugin
     */
    public function __construct(SkyWars $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @param EntityDamageEvent $event
     */
    public function onEntityDamage(EntityDamageEvent $event) : void{
        $entity = $event->getEntity();
        if($entity instanceof Player) {
            $arena = $this->plugin->getPlayerArena($entity);
            if ($arena == null) return;


            if ($arena->GAME_STATE == Arena::STATE_RUNNING) {
                if ($event->getFinalDamage() >= $entity->getHealth()) {
                    $event->setCancelled();
                    if (!isset($arena->spectators[$entity->getName()])) {
                        $arena->removePlayer($entity, false, true);
                        $this->plugin->sendDeathMessage($arena, $event->getEntity());
                    }
                }
            }
        }
    }

    /**
     * @var array
     */
    private $arenaOrder = [];

    /**
     * @param DataPacketReceiveEvent $event
     */
    public function onPacketReceive(DataPacketReceiveEvent $event) : void{
        $player = $event->getPlayer();
        $packet = $event->getPacket();

        if($packet instanceof ModalFormResponsePacket){
            if($packet->formId == 599){
                $data = json_decode($packet->formData);
                if(is_null($data)){
                    return;
                }
                switch($data){
                    case 0;
                        $arena = $this->plugin->findBestArena();
                        $arena->join($player);
                        break;
                    case 1;
                        $formData = [];
                        $formData['title'] = "Arena List";
                        $formData['content'] = "";
                        $formData['type'] = "form";
                        $a = 0;
                        foreach($this->plugin->arenas as $arena){
                            $formData['buttons'][] = ['text' => $arena->getName() . " " . TextFormat::YELLOW . count($arena->players) . "/" . $arena->slots . "\n" . TextFormat::GRAY . "Type: " . TextFormat::BOLD . TextFormat::GREEN . ucfirst($arena->getType())];
                            $this->arenaOrder[$player->getName()][$a] = $arena->getName();
                            $a++;
                        }
                        $packet = new ModalFormRequestPacket();
                        $packet->formId = 600;
                        $packet->formData = json_encode($formData);
                        $player->dataPacket($packet);
                        break;
                }
            }elseif($packet->formId == 600){
                $formData = json_decode($packet->formData);
                if(is_null($formData)){
                    return;
                }

                if(in_array(intval($formData), range(0, count($this->plugin->arenas)))){
                    $this->plugin->arenas[array_values($this->arenaOrder[$player->getName()])[intval($packet->formData)]]->join($player);
                }

            }
        }
    }

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);
        if($arena !== null){
            $arena->removePlayer($player, true, false);
        }
    }

    /**
     * @param EntityLevelChangeEvent $event
     */
    public function onLevelChange(EntityLevelChangeEvent $event) : void{
        $player = $event->getEntity();
        if(!$player instanceof Player)return;
        $target = $event->getTarget();
        $arena = $this->plugin->getPlayerArena($player);
        if($arena !== null){
            if($arena->getWorldName() !== $target->getFolderName()){
                $arena->removePlayer($player, true, false);
            }
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBlockBreak(BlockBreakEvent $event) : void{
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);
        if($arena !== null && $arena->GAME_STATE == Arena::STATE_COUNTDOWN){
            $event->setCancelled();
        }
    }

    /**
     * @param BlockPlaceEvent $event
     */
    public function onBlockPlace(BlockPlaceEvent $event) : void{
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);
        if($arena !== null && $arena->GAME_STATE == Arena::STATE_COUNTDOWN){
            $event->setCancelled();
        }
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) : void{
        $player = $event->getPlayer();
        $arena = $this->plugin->getPlayerArena($player);
        if($arena !== null && in_array($player->getName(), array_keys($arena->getAlivePlayers()))){
            if($player->getY() < $arena->void){
                $arena->removePlayer($player, false, true);
                $this->plugin->sendDeathMessage($arena, $player, EntityDamageEvent::CAUSE_VOID);
            }
        }
    }





}