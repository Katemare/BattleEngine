<?
if (!defined('GOD')) exit;

###############
### MONSTER ###
###############
{
class Monster extends Battler
{
	public $stats='MonsterStats';
	
	public function damageResistance($effect)
	{
		if ($this->dmgmult[$effect->dmgtype]<>1) return $effect->damage*$this->dmgmult[$effect->dmgtype];
		else return $effect->damage;
	}
	
	public function Faint()
	{
		$this->conditions->add('Fainted');
	}
}
}

#############
### STATS ###
#############
{
class MonsterStats extends Stats
{
	public function __construct($stats, $owner)
	{
		parent::__construct($stats, $owner);
		$this->limits['hp']=array('max'=>'max');
		$this->limits['default']=array('min'=>0);
	}
	
	public function getStat($name, $who=null)
	{
		$consult=$this->consult($name, array('action'=>'get'), $who);
		if (array_key_exists('value', $consult)) return $consult['value'];
		elseif ($consult['mod']<>0) return (self::applyMod($this->list[$name], $consult['mod']));
		else return $this->list[$name];
	}	

	const levproc=0.05, maxlev=5;	
	static function applyMod($value, $mod)
	{
		return $value*(1+minmax($mod, -self::maxlev, self::maxlev)*self::levproc);
	}
	
	public function validateChange($name, $new)
	{
		if ($new<0) $new=0;
		if ($name=='hp')
		{
			$max=$this->getStat('maxhp');
			if ($new>$max) $new=$max;
		}
		return $new;
	}
	
	public function changeStat($name, $new, $who=null, $op=null)
	{
		$new=parent::changeStat($name, $new, $who, $op);
		if (($name=='hp')&&($new==0))
		{
			$this->owner->Faint();
		}
		return $new;
	}
}

class MoveStats extends Stats
{
	public $counters=array('PP');
}

}

##################
### CONDITIONS ###
##################
{
class cndStatMod extends Condition implements Consultant
{
	public $stat=null, $lev=0;
	
	public function __construct($owner, $stat, $lev)
	{
		parent::__construct($owner, 'statmod');
		$owner->stats->addConsultant($this, $stat);
		$this->stat=$stat;
		$this->lev=$lev;
	}
	
	public function checkStat($stats, &$data, $who)
	{
		if (($data['name']==$this->stat)&&($data['action']=='get')) $data['mod']+=$this->lev;
	}
}

class cndFainted extends Condition
{
	use HookCatcher;
	public function __construct($owner)
	{
		parent::__construct($owner);
		$owner->field->hooks->addHook($this, 'initAction', array('stage'=>EffectChain::STAGE_GATHER, 'user'=>$owner));
	}
	
	public function catchedHook($effect)
	// this should receive only 'initAction' effects and in gather stage.
	{
		$effect->gather['used_action']=null; // fainted monsters can't act.
		return 'ok';
	}
}

class cndPoison extends regularCondition
{
	public $power=10;
	
	public function createConditionEffect($initdata, $gather, $result)
	{
		$e=new Damage(array('condition'=>$this), $gather['source'], $this->power, 'raw');
		return $e;
	}
}

}

#######################
### MOVES (actions) ###
#######################
{
abstract class MonsterMove extends Action
{
	use StatReader;
	public $stats=null;

	public function __construct ($data)
	{
		$this->stats=new MoveStats($data, $this);
	}
}

class Attack extends MonsterMove // this covers basic damaging moves.
{	
	public function createDamage($data)
	{
		$e=new Damage(array('user'=>$data['user'], 'used_action'=>$this), $data['targets']);
		return $e;
	}
	
	public function createHitEffect($data)
	{
		return new Hit(array('user'=>$data['user'], 'used_action'=>$this), $data['targets']);
	}
	
	public function createActionEffect($initdata, $gather, $result)
	{
		$targets=$gather['user']; // DEBUG
		$result['targets']=$targets; // STUB
		if ($this->getStat('accuracy')==0) return $this->createDamage($result);
		else return $this->createHitEffect($result);
	}
	
	public function createOnHitEffect($initdata, $gather, $result)
	{
		$targets=$gather['user']; // DEBUG
		$result['targets']=$targets; // STUB
		if ($result['resolution']=='hit') return $this->createDamage($result);
	}
}
}

###############
### EFFECTS ###
###############
{
class Damage extends targetedEffect
{	
	public function __construct($initdata, $targets)
	// STUB
	{
	// initdata should contain fields 'user' and 'used_action'.
		parent::__construct($initdata, $targets);
		self::$log_formats['damage']='Нанесён урон {damage}!';
	}
	
	public function gather()
	{
		$report=parent::gather();
		if ($report=='stop') return $report;
		if ($this->iteration==1) $report='repeat';
		elseif (($this->iteration==2)&&(is_object($this->gather['used_action'])))
		{
			$this->gather['category']=$this->gather['used_action']->getStat('category');
			$this->gather['power']=$this->gather['used_action']->getStat('power');
			$this->gather['type']=$this->gather['used_action']->getStat('type');
			
			if ($this->gather['category']=='special') $this->gather['stat']=$this->gather['user']->getStat('spatt', $this);
			elseif ($this->gather['category']=='physical') $this->gather['stat']=$this->gather['user']->getStat('att', $this);
			$this->gather['user_types']=array(1=>$this->gather['user']->getStat('type1', $this), 2=>$this->gather['user']->getStat('type2', $this) );
			
		}
		return $report;
	}
	
	public function form()
	{
		$report=parent::form();
		if (($report!=='stop')&&($this->gather['power']>0)&&($this->iteration==1))
		{
			$this->result['damage']=mt_rand(1, $this->gather['power']); // STUB
		}
		return $report;
	}
	
	public function execute()
	{
		// STUB: all targets receive same damage without consideraction for their abilities.
		$report=parent::execute();
		if (($report!=='stop')&&($this->result['damage']>0)&&($this->iteration==1))
		{
			$this->gather['user']->field->chain->log(array('action'=>'damage', 'damage'=>$this->result['damage']), $this);
			foreach ($this->targets as $target)
			{
				$target->stats->changeStat('hp', -1*$this->result['damage'], $this, 'add');
			}
		}
		return $report;
	}
}

class Hit extends targetedEffect
{
	public function __construct($initdata, $targets)
	// STUB
	{
	// initdata should contain fields 'user' and 'used_action'.
		parent::__construct($initdata, $targets);
		
		self::$log_formats['hit']='Проверка попадания!';
	}
	
	public function gather()
	{
		$report=parent::gather();
		if ($report=='stop') return $report;
		if ($this->iteration==1) $report='repeat';
		elseif ($this->iteration==2)
		{
			// STUB
			$this->gather['accuracy']=$this->gather['used_action']->getStat('accuracy');

		}
		return $report;
	}
	
	public function form()
	{
		$report=parent::form();
		if (($report!=='stop')&&($this->iteration==1))
		{
			$this->result['dice']=mt_rand(1, 100); // STUB
		}
		return $report;
	}
	
	public function execute()
	{
		// STUB: all targets receive same damage without consideraction for their abilities.
		$report=parent::execute();
		if (($report!=='stop')&&($this->iteration==1))
		{
			if ($this->result['dice']<=$this->gather['accuracy']) $this->result['resolution']='hit';
			else $this->result['resolution']='miss';
			
			$this->gather['user']->field->chain->log(array('action'=>'hit', 'resolution'=>$this->result['resolution']), $this);
			$e=$this->gather['used_action']->createOnHitEffect($this->initdata, $this->gather, $this->result);
			if ($e) $this->gather['user']->field->cascadeAddEffect($e);
			//echo '111'.$report; var_dump($e); exit;
		}
		return $report;
	}
}

class MoveSelect extends Input
{
	public $input_type='move select';
	
	public function gather()
	{
		$report=parent::gather();
		if ($report!=='stop')
		{
			if ($this->iteration==1) $report='repeat';
			else $this->gather['choice']=$this->gather['battler']->moves();
		}
		return $report;
	}

	public function validate($data='')
	{
		if ($this->completed) return;
		if (in_array($data['pick'], array_keys($this->gather['choice']), 1))
		{
			$result['pick']=$data['pick'];
			$this->completed=true;
		}
	}
}
}
?>