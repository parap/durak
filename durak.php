<?php

class Configuration
{
    const MIN_PLAYERS = 2;
    const MAX_PLAYERS = 4;
    const CARD_SUITS = ['♠', '♥', '♣', '♦'];
    const CARD_VALUES = ['6', '7', '8', '9', '10', 'В', 'Д', 'К', 'Т'];
    const ITERATIONS_TOTAL = 1000;
    const CARDS_AT_HAND = 6;
    const DRAW_INDICATION = '-';
//    const DEBUG = true;
    const DEBUG = false;
}

class GameFool
{
    const ROUND_OUTCOME_NONE = '';
    const ROUND_OUTCOME_ATTACKER_SUCCEEDED = 'success';
    const ROUND_OUTCOME_ATTACKER_FAILED = 'fail';
    const DRAW = 'fail';
    /** @var Player[] $players */
    protected $players = [];
    /** @var CardsDeck $deck */
    protected $deck;

    /** @var Player $attacker */
    protected $attacker = null;

    /** @var Player $defender */
    protected $defender = null;

    /** @var TableStack $tableStack */
    protected $tableStack = null;

    /**
     * @return TableStack
     */
    public function getTableStack(): TableStack
    {
        return $this->tableStack;
    }

    /**
     * @param TableStack $tableStack
     * @return GameFool
     */
    public function setTableStack(TableStack $tableStack): GameFool
    {
        $this->tableStack = $tableStack;
        return $this;
    }

    /**
     * @return Player
     */
    public function getAttacker(): Player
    {
        return $this->attacker;
    }

    /**
     * @param Player $attacker
     * @return GameFool
     */
    public function setAttacker(Player $attacker): GameFool
    {
        $this->attacker = $attacker;
        return $this;
    }

    /**
     * @return Player
     */
    public function getDefender(): Player
    {
        return $this->defender;
    }

    /**
     * @param Player $defender
     * @return GameFool
     */
    public function setDefender(Player $defender): GameFool
    {
        $this->defender = $defender;
        return $this;
    }

    /**
     * @return Player[]
     */
    public function getPlayers(): array
    {
        return $this->players;
    }

    /**
     * @param Player[] $players
     * @return GameFool
     */
    public function setPlayers(array $players): GameFool
    {
        $this->players = $players;
        return $this;
    }

    public function __construct()
    {
        $this->tableStack = new TableStack();
    }

    /**
     * @param null $argument
     * @return GameFool|string
     * @throws ErrorException
     */
    public function __invoke($argument = null)
    {
        if ($argument instanceof CardsDeck) {
            return $this->setDeck($argument);
        }

        if ($argument instanceof Player) {
            return $this->addPlayer($argument);
        }

        if (!$this->validatePlayersAmount()) {
            throw new InvalidArgumentException('Wrong players number');
        }

        $this->initiallyGivePlayersCardsFromDeck();
        $this->getDeck()->initTrump();
        $this->getDeck()->initCardWeights();

        foreach ($this->getPlayers() as $player) {
            $player->initCardWeights($this->getDeck()->getTrump());
            $player->sortCards();
        }

        $result = $this->playGame();
        $output = Configuration::DRAW_INDICATION === $result ? 'Draw' : 'Fool: ' . $result;
        return Configuration::DEBUG ? PHP_EOL . PHP_EOL . $output : $result;
    }

    protected function validatePlayersAmount(): bool
    {
        return count($this->players) <= Configuration::MAX_PLAYERS
            && count($this->players) >= Configuration::MIN_PLAYERS;
    }

    protected function addPlayer(Player $player): GameFool
    {
        $this->players[] = $player;
        return $this;
    }

    protected function setDeck(CardsDeck $deck): GameFool
    {
        $this->deck = $deck;
        return $this;
    }

    protected function getDeck(): CardsDeck
    {
        return $this->deck;
    }

    protected function log(string $output1, string $output2 = null): void
    {
        if (!Configuration::DEBUG) {
            return;
        }

        if ((bool)$output2) {
            echo PHP_EOL . $output1 . ': ' . $output2;
            return;
        }

        echo PHP_EOL . $output1;
    }

    /**
     * @return string
     * @throws ErrorException
     */
    protected function playGame(): string
    {
        $roundOutcome = self::ROUND_OUTCOME_NONE;
        $iteration = 1;

        $this->log('Deck random', $this->getDeck()->getSeed());
        $this->log('Trump', $this->getDeck()->getTrumpCard()->getName());
        foreach ($this->getPlayers() as $player) {
            $this->log($player->getName(), $player->getCardsAsString());
        }

        while ($this->twoOrMorePlayersLeft()) {
            $this->initializeAttacker($roundOutcome);
            $this->initializeDefender();

            $this->log('');
            $this->log(
                sprintf('%02d', $iteration),
                $this->getAttacker()->getName() . '(' . $this->getAttacker()->getCardsAsString() .
                ') vs ' . $this->getDefender()->getName() . ' (' . $this->getDefender()->getCardsAsString() . ')'
            );

            $roundOutcome = $this->playRound();
            $this->loadCardsFromDeckTo($this->getAttacker());
            $this->loadCardsFromDeckTo($this->getDefender());

            $iteration++;

            if ($iteration > 100) {
                throw new ErrorException('Too many iterations');
            }
        }

        return $this->singlePlayerLeft() ? $this->singlePlayerLeft()->getName() : Configuration::DRAW_INDICATION;
    }

    /**
     * @return string
     * @throws ErrorException
     */
    protected function playRound(): string
    {
        $attacker = $this->getAttacker();
        $defender = $this->getDefender();
        $stack = $this->getTableStack();
        $trump = $this->getDeck()->getTrump();

        $iteration = 0;
        while ($attacker->hasCards() && $defender->hasCards()) {
            $iteration++;

            $attackingCard = $attacker->getAttackingCard($stack, $trump, $iteration);
            if (!$attackingCard instanceof Card) {
                $stack->purify();
                return self::ROUND_OUTCOME_ATTACKER_FAILED;
            }

            $stack->addCard($attackingCard);
            $attacker->removeCard($attackingCard);
            $this->log($this->getAttacker()->getName() . ' -->' . $attackingCard->getName());

            $defendingCard = $defender->getDefendingCard($attackingCard, $trump);
            if (!$defendingCard instanceof Card) {
                $attacker->dumpSuitableCardsToStack($stack, $trump);

                foreach ($this->getTableStack()->getCards() as $card) {
                    $this->log($defender->getName() . ' <--' . $card->getName());
                }

                $defender->takesTableStack($stack);
                $stack->purify();
                return self::ROUND_OUTCOME_ATTACKER_SUCCEEDED;
            }

            $this->log($defendingCard->getName() . ' <--' . $this->getDefender()->getName());
            $defender->removeCard($defendingCard);
            $stack->addCard($defendingCard);

            if ($iteration > 100) {
                throw new ErrorException('Too many iterations in a round');
            }
        }

        return self::ROUND_OUTCOME_ATTACKER_FAILED;
    }

    /**
     * @return null|Player
     */
    protected function singlePlayerLeft()
    {
        $total = 0;
        $playerLeft = null;
        foreach ($this->getPlayers() as $player) {
            if (!$player->hasCards()) {
                continue;
            }

            $playerLeft = $player;
            $total++;
        }

        return $total === 1 ? $playerLeft : null;
    }

    /**
     * @param string $roundOutcome
     * @throws ErrorException
     */
    protected function initializeAttacker(string $roundOutcome): void
    {
        $attacker = $this->findAttacker($roundOutcome);
        $this->setAttacker($attacker);
    }

    /**
     * @throws ErrorException
     */
    protected function initializeDefender(): void
    {
        $defender = $this->findDefender();
        $this->setDefender($defender);
    }

    /**
     * @param string $roundOutcome
     * @return Player
     * @throws ErrorException
     */
    protected function findAttacker(string $roundOutcome): Player
    {
        if (self::ROUND_OUTCOME_NONE === $roundOutcome) {
            return $this->getPlayers()[0];
        }

        if (self::ROUND_OUTCOME_ATTACKER_FAILED === $roundOutcome) {
            return $this->getNextPlayerAfter($this->getAttacker());
        }

        if (self::ROUND_OUTCOME_ATTACKER_SUCCEEDED === $roundOutcome) {
            return $this->getNextPlayerAfter($this->getDefender());
        }

        throw new ErrorException('Absent $roundOutcome value was encountered');
    }

    /**
     * @return Player
     * @throws ErrorException
     */
    protected function findDefender(): Player
    {
        return $this->getNextPlayerAfter($this->getAttacker());
    }

    /**
     * @param Player $givenPlayer
     * @return Player
     * @throws ErrorException
     */
    protected function getNextPlayerAfter(Player $givenPlayer): Player
    {
        $index = array_search($givenPlayer, $this->getPlayers());
        foreach ($this->getPlayers() as $key => $player) {
            if ($key > $index && $player->hasCards()) {
                return $player;
            }
        }

        foreach ($this->getPlayers() as $key => $player) {
            if ($key < $index && $player->hasCards()) {
                return $player;
            }
        }

        throw new ErrorException('Failed to find next player');
    }

    /**
     * @return int
     */
    protected function countPlayersLeft(): int
    {
        $result = 0;
        foreach ($this->getPlayers() as $player) {
            if ($player->hasCards()) {
                $result++;
            }
        }

        return $result;
    }

    /**
     * @return bool
     */
    protected function twoOrMorePlayersLeft(): bool
    {
        return $this->countPlayersLeft() > 1;
    }

    protected function initiallyGivePlayersCardsFromDeck(): void
    {
        foreach ($this->getPlayers() as $player) {
            for ($i = 0; $i < Configuration::CARDS_AT_HAND; $i++) {
                $this->playerTakesCardFromDeck($player);
            }
        }
    }

    protected function loadCardsFromDeckTo(Player $player): void
    {
        $needCards = Configuration::CARDS_AT_HAND - $player->countCards();
        for ($i = 0; $i < $needCards; $i++) {
            $this->playerTakesCardFromDeck($player);
        }
    }

    /**
     * @param Player $player
     */
    protected function playerTakesCardFromDeck(Player $player): void
    {
        $deck = $this->getDeck();
        if (!$deck->hasCards()) {
            return;
        }

        $card = $deck->getCard(0);

        if (!$card instanceof Card) {
            throw new InvalidArgumentException('Failed to find a card in deck');
        }

        $this->log('(deck) ' . $player->getName() . ' + ' . $card->getName());

        $player->addCard($card);
        $deck->removeFirstCardAndRearrange();
    }
}

class Player
{
    /** @var string $name */
    protected $name;

    /** @var Card[] $cards */
    protected $cards;

    /**
     * Player constructor.
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return Card[]
     */
    public function getCards(): array
    {
        return $this->cards;
    }

    public function hasCards(): bool
    {
        return count($this->getCards()) > 0;
    }

    public function countCards(): int
    {
        return count($this->getCards());
    }

    public function isHighestTrump(Card $card, $trump): bool
    {
        return $card->isTrump($trump) && $card === end($this->cards);
    }

    /**
     * @param Card $card
     * @return Player
     */
    public function addCard(Card $card): Player
    {
        $this->cards[] = $card;
        $this->sortCards();

        return $this;
    }

    public function removeCard(Card $cardToRemove)
    {
        $keyToRemove = null;
        foreach ($this->getCards() as $key => $card) {
            if ($card->getName() === $cardToRemove->getName()) {
                unset($this->cards[$key]);
            }
        }

        $this->sortCards();
    }

    /**
     * @param TableStack $stack
     * @param string $trump
     * @param int $iteration
     * @return Card|null
     */
    public function getAttackingCard(TableStack $stack, string $trump, int $iteration)
    {
        if (!$stack->hasCards()) {
            return $this->getFirstCard();
        }

        foreach ($this->getCards() as $card) {
            if ($this->isHighestTrump($card, $trump) && $iteration > 0) {
                continue;
            }

            if (in_array($card->getValue(), $stack->getCardValues())) {
                return $card;
            }
        }

        return null;
    }

    public function getFirstCard()
    {
        return reset($this->cards);
    }

    /**
     * @param Card $attackingCard
     * @param string $trump
     * @return Card|null
     */
    public function getDefendingCard(Card $attackingCard, string $trump)
    {
        foreach ($this->getCards() as $card) {
            if ($card->getSuit() === $attackingCard->getSuit() && $card->getWeight() > $attackingCard->getWeight()) {
                return $card;
            }
        }

        if (!$attackingCard->isTrump($trump)) {
            return $this->getLowestTrump($trump);
        }

        foreach ($this->getCards() as $card) {
            if ($card->isTrump($trump) && $card->getWeight() > $attackingCard->getWeight()) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @param $trump
     * @return Card|null
     */
    protected function getLowestTrump($trump)
    {
        foreach ($this->getCards() as $card) {
            if ($card->isTrump($trump)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Также, у нападающего забираются все карты такого же достоинства, что были использованы за ход, кроме козырей.
     * Иными словами, если козырь черва, за ход использовалась карта 10 пика и на в руках нападающего есть ещё 10
     * чева и 10 бубен, то 10 бубен переходит в руки отбивающегося.
     *
     * @param TableStack $stack
     * @param string $trump
     */
    public function dumpSuitableCardsToStack(TableStack $stack, string $trump): void
    {
        $usedCardValues = $stack->getCardValues();
        foreach ($this->getCards() as $card) {
            if ($card->isTrump($trump)) {
//            if ($this->isHighestTrump($card, $trump) ) {
                continue;
            }

            if (in_array($card->getValue(), $usedCardValues)) {
                $stack->addCard($card);
                $this->removeCard($card);
            }
        }
    }

    /**
     * @param TableStack $stack
     */
    public function takesTableStack(TableStack $stack): void
    {
        foreach ($stack->getCards() as $card) {
            $this->addCard($card);
        }

        $this->sortCards();
    }

    public function sortCards(): void
    {
        $sortByWeight = function (Card $a, Card $b) {
            if ($a->getWeight() === $b->getWeight()) {
                return 0;
            }

            return $a->getWeight() < $b->getWeight() ? -1 : 1;
        };
        usort($this->cards, $sortByWeight);
    }

    /**
     * @return string
     */
    public function getCardsAsString(): string
    {
        $result = [];
        foreach ($this->getCards() as $card) {
            $result[] = $card->getName();
        }
        return implode(', ', $result);
    }

    public function initCardWeights($trump): void
    {
        foreach ($this->getCards() as $card) {
            $card->setWeight($card->calculateWeight($trump));
        }
    }
}

class CardsDeck
{
    /** @var Card[] $cards */
    protected $cards = [];
    /** @var int $seed */
    protected $seed;

    /** @var string $trump */
    protected $trump;

    public function __construct(int $seed)
    {
        $this->setSeed($seed);
        $this->loadDefaultCards();
        $this->randomizeCards();
    }

    /**
     * @return bool
     */
    public function hasCards(): bool
    {
        return count($this->getCards()) > 0;
    }

    /**
     * @return string
     */
    public function getTrump(): string
    {
        return $this->trump;
    }

    /**
     * @return Card
     */
    public function getTrumpCard(): Card
    {
        $cards = $this->getCards();
        return end($cards);
    }

    /**
     * @param string $trump
     * @return CardsDeck
     */
    public function setTrump(string $trump): CardsDeck
    {
        $this->trump = $trump;
        return $this;
    }

    /**
     * @param Card $card
     */
    public function addCard(Card $card): void
    {
        $this->cards[] = $card;
    }

    /**
     * @return Card[]
     */
    public function getCards(): array
    {
        return $this->cards;
    }

    /**
     * @return int
     */
    public function getSeed(): int
    {
        return $this->seed;
    }

    /**
     * @param int $seed
     * @return CardsDeck
     */
    public function setSeed(int $seed): CardsDeck
    {
        $this->seed = $seed;
        return $this;
    }

    protected function loadDefaultCards(): void
    {
        foreach (Configuration::CARD_SUITS as $suit) {
            foreach (Configuration::CARD_VALUES as $value) {
                $this->addCard(new Card($suit, $value));
            }
        }
    }

    protected function randomizeCards(): void
    {
        for ($i = 0; $i < Configuration::ITERATIONS_TOTAL; $i++) {
            $index = ($this->getSeed() + $i * 2) % 36;
            $this->moveCardToBegin($index);
        }
    }

    public function removeFirstCardAndRearrange(): void
    {
        array_shift($this->cards);
    }

    public function removeCard($index): void
    {
        unset($this->cards[$index]);
    }

    public function getCard($index): Card
    {
        return $this->cards[$index];
    }

    protected function moveCardToBegin($index): void
    {
        $card = $this->getCard($index);
        $this->removeCard($index);
        array_unshift($this->cards, $card);
    }

    public function initTrump(): void
    {
        $trumpCard = array_shift($this->cards);
        $this->addCard($trumpCard);
        $this->setTrump($trumpCard->getSuit());
    }

    public function initCardWeights(): void
    {
        foreach ($this->getCards() as $card) {
            $card->setWeight($card->calculateWeight($this->getTrump()));
        }
    }
}

class Card
{
    const WEIGHT_TRUMP_COEFFICIENT = 1000;
    const WEIGHT_VALUE_COEFFICIENT = 10;
    /** @var string $value */
    protected $value;
    /** @var string $suit */
    protected $suit;
    /** @var int $weight */
    protected $weight = 0;

    public function __construct(string $suit, string $value)
    {
        $this->setValue($value);
        $this->setSuit($suit);
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->getValue() . $this->getSuit();
    }

    /**
     * @param string $value
     * @return Card
     */
    public function setValue(string $value): Card
    {
        $this->value = $value;
        return $this;
    }

    /**
     * @return string
     */
    public function getSuit(): string
    {
        return $this->suit;
    }

    /**
     * @param string $suit
     * @return Card
     */
    public function setSuit($suit): Card
    {
        $this->suit = $suit;
        return $this;
    }

    /**
     * @return int
     */
    public function getWeight(): int
    {
        return $this->weight;
    }

    /**
     * @param int $weight
     * @return Card
     */
    public function setWeight(int $weight): Card
    {
        $this->weight = $weight;
        return $this;
    }

    /**
     * @param string $trump
     * @return int
     */
    public function calculateWeight(string $trump): int
    {
        $suitIndex = array_flip(Configuration::CARD_SUITS)[$this->getSuit()];
        $valueIndex = array_flip(Configuration::CARD_VALUES)[$this->getValue()];
        $trumpBonus = $trump === $this->getSuit() ? self::WEIGHT_TRUMP_COEFFICIENT : 0;

        return $trumpBonus + $suitIndex + $valueIndex * self::WEIGHT_VALUE_COEFFICIENT;
    }

    /**
     * @param string $trump
     * @return bool
     */
    public function isTrump(string $trump): bool
    {
        return $this->getSuit() === $trump;
    }
}

class TableStack
{
    /** @var Card[] $cards */
    protected $cards = [];

    /**
     * @return Card[]
     */
    public function getCards(): array
    {
        return $this->cards;
    }

    /**
     * @param Card $card
     * @return TableStack
     */
    public function addCard(Card $card): TableStack
    {
        $this->cards[] = $card;
        return $this;
    }

    public function purify(): void
    {
        $this->cards = [];
    }

    /**
     * @return int
     */
    public function hasCards(): int
    {
        return count($this->getCards());
    }

    /**
     * @return array
     */
    public function getCardValues(): array
    {
        $result = [];
        foreach ($this->getCards() as $card) {
            $result[] = $card->getValue();
        }

        return $result;
    }
}
