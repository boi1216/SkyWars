<?php

namespace SkyWars;


use pocketmine\Player;

class Team
{

    /** @var string $color */
    private $color;

    /** @var string $name */
    private $name;

    /** @var array $players */
    public $players = [];

    /**
     * Team constructor.
     * @param string $name
     * @param string $color
     */
    public function __construct(string $name, string $color)
    {
        $this->color = $color;
        $this->name = $name;
    }

    /**
     * @return array
     */
    public function getPlayers() : array{
        return $this->players;
    }

    /**
     * @param Player $player
     */
    public function addPlayer(Player $player) : void{
        $this->players[$player->getName()] = $player;
     }

    /**
     * @return string
     */
     public function getName() : string{
        return $this->name;
     }

    /**
     * @return string
     */
     public function getColor() : string{
         return $this->color;
     }

}