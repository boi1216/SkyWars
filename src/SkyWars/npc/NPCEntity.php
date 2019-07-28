<?php

namespace SkyWars\npc;


use pocketmine\entity\Human;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\Player;
use pocketmine\utils\TextFormat;
use SkyWars\SkyWars;

class NPCEntity extends Human
{

    /**
     * @param int $tickDiff
     * @return bool
     */
    public function entityBaseTick(int $tickDiff = 1): bool
    {
        $playerCount = 0;
        foreach(SkyWars::$instance->arenas as $arena){
            $playerCount += count($arena->players);
        }

        $this->setNameTag(TextFormat::AQUA . TextFormat::BOLD . "SkyWars§r\n" . TextFormat::YELLOW . $playerCount . " Playing");
        return parent::entityBaseTick($tickDiff);
    }

    /**
     * @param EntityDamageEvent $source
     */
    public function attack(EntityDamageEvent $source): void
    {
        $source->setCancelled();
        if(!$source instanceof EntityDamageByEntityEvent)return;
        $damager = $source->getDamager();
        if(!$damager instanceof Player)return;
        $formData = [];
        $formData['title'] = "SurvivalGames Menu";
        $formData['type'] = "form";
        $arena = SkyWars::$instance->findBestArena();
        $formData['buttons'][] = ['text' => "Join current arena\n" . TextFormat::RESET . $arena->getName() . " " . TextFormat::YELLOW . count($arena->players) . "/" . $arena->slots . " " . TextFormat::GRAY . "Type: " . TextFormat::BOLD . TextFormat::GREEN . ucfirst($arena->getType())];
        $formData['buttons'][] = ['text' => 'Select arena'];
        $formData['content'] = "";
        $packet = new ModalFormRequestPacket();
        $packet->formId = 599;
        $packet->formData = json_encode($formData);
        $damager->dataPacket($packet);
    }


}