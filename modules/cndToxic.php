<?
class cndToxic extends cndPoisoned
{
	public $strength=0.4;
	public function __construct(Battler $owner)
	{
		parent::__construct($owner, $this->strength);
		$this->type='toxic';
	}
}
?>