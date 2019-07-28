<?php

namespace SkyWars\scoreboard;


use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\Player;

class Scoreboard
{

    /** @var int $line */
    private static $eid;
    /**
     * @param Player $player
     * @param string $objective
     */
    public static function removeBoard(Player $player, string $objective) {
        $pk = new RemoveObjectivePacket();
        $pk->objectiveName = $objective;
        $player->dataPacket($pk);
    }
    /**
     * @param Player $player
     * @param string $text
     * @param string|null $objectiveName
     */
    public static function sendBoard(Player $player, string $text, string $objectiveName = null) {
        if($objectiveName === null) $objectiveName = strtolower($player->getLevel()->getFolderName());
        $lines = explode(PHP_EOL, $text);
        $title = array_shift($lines);
        foreach (self::buildBoard($objectiveName, $title, implode(PHP_EOL, $lines)) as $packet) {
            $player->dataPacket($packet);
        }
    }

    /**
     * @param string $id
     * @param string $text
     * @param Player $player
     */
    public static function renameBoard(string $id, string $text, Player $player) : void{
        $pk1= new RemoveObjectivePacket();
        $pk1->objectiveName = $id;

        $packets[] = $pk1;

        $lines = explode(PHP_EOL, $text);
        foreach(self::buildBoard($id, array_shift($lines), implode(PHP_EOL, $lines)) as $pk){
            $packets[] = $pk;
        }
        foreach($packets as $packet){
            $player->dataPacket($packet);
        }
    }

    /**
     * @param string $id
     * @param string $title
     * @param string $text
     * @return array
     */
    public static function buildBoard(string $id, string $title, string $text) {
        $pk = new SetDisplayObjectivePacket();
        $pk->objectiveName = $id;
        $pk->displayName = $title;
        $pk->sortOrder = 0;
        $pk->criteriaName = "dummy";
        $pk->displaySlot = "sidebar";
        $packets[] = clone $pk;
        self::$eid = Entity::$entityCount++;
        $pk = new SetScorePacket();
        $pk->type = $pk::TYPE_CHANGE;
        $pk->entries = self::buildLines($id, $text);
        $packets[] = clone $pk;
        return $packets;
    }

    /**
     * @param string $id
     * @param string $text
     * @return ScorePacketEntry[] $lines
     */
    private static function buildLines(string $id, string $text): array {
        $texts = explode(PHP_EOL, $text);
        $lines = [];
        foreach ($texts as $line) {
            $entry = new ScorePacketEntry();
            $entry->score = count($lines);
            $entry->scoreboardId = count($lines);
            $entry->objectiveName = $id;
            $entry->entityUniqueId = self::$eid;
            $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
            $entry->customName = " " . $line . " "; // it seems better
            $lines[] = $entry;
        }
        return $lines;
    }

}