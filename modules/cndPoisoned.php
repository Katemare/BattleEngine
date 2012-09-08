<?
class cndPoisoned extends Condition
{
	public $tickphase='before_turn';
	public $strength=0.2;
	public function __construct(Battler $owner, $strength=0.2)
	{
		parent::__construct($owner, 'poisoned');
		$this->strength=$strength;
	}
	
	public function tick($phase)
	{	
		$tick=parent::tick();
		if (($tick)&&($phase==$this->tickphase)) $this->poison();
		return $tick;
	}
	
	public function poison()
	{
		$this->owner->receiveRelativeDamage($this->strength);
	}
}
?>