<?
if (!defined('GOD')) exit;
// seed with microseconds
function make_seed()
{
  list($usec, $sec) = explode(' ', microtime());
  return (float) $sec + ((float) $usec * 100000);
}
mt_srand(make_seed());

function __autoload ($name)
{
	$file='modules/'.$name.'.php';
	if (!file_exists($file)) { echo "Error! $name"; exit; }
	include ($file);
}

##############
### USEFUL ###
##############
{

function minmax($val, $min, $max)
{
	if ($val>$max) return $max;
	if ($val<$min) return $min;
	return $val;
}

function format($format, $data)
{
	preg_match_all('/\{([^\}]+?)\}/', $format, $m);
	foreach ($m[1] as $replace)
	{
		$format=str_replace('{'.$replace.'}', $data[$replace], $format);
	}
	
	return $format;
}

}

###################
### BATTLEFIELD ###
###################
{
class Battlefield // this class contains everything what happens in battle. serialize it to save the game.
{
	public $battlers=array(); // list of all battlers by id
	public $environment=null; // a Conditions object for conditions that are attached to the field itself and not to battlers or players.
	public $chain=null; // an EffectChain object - current chain of effects. there can be only one at a time, or null, if none exists currently.
	public $hooks=null; // a Hook object.
	public $round=0; // counter of rounds.
	public $phase=1;
	const PHASE_BEFORE_TURN=1, PHASE_ATTACK_CHOSEN=2, PHASE_ATTACK=3, PHASE_AFTER_TURN=3, PHASE_LAST=3;
	public $players=array(); // list of ids of human and CPU entities controlling the battlers.
	
	public function __construct($players)
	{
		$this->hooks=new Hooks($this);
		$this->environment=new Conditions($this);
		$this->players=$players; // players shuold be an array of numeric ids.
		// in game systems where player has meterial representations in the field - for example, has hit points -
		// this function should create Battler objects for players.
	}
	
	public function tick() // progresses all battlers to the current phase.
	// This covers auto-effects, such as conditions progress and time-based triggers.
	// This doesn't include effect chain: it resolves by itself, possibly leaving conditions and permanent changes behind.
	// The 'wait' resolution means that some objects cannot progress yet - usually because they depend on other objects or wait for input.
	// In case of 'wait' resolution, objects are expected to keep track of if they've ticked this phase, because this function will run again from the beginning.
	{
		$wait=false;
		$report=$this->environment->tick(); // tick progresses an object to the desired phase, current by default.
		if ($report=='wait') $wait=true;
		foreach ($this->battlers as $battler)
		{
			$report=$battler->tick(); // tick progresses an object to the desired phase.
			if ($report=='wait') $wait=true;
		}
		
		if ($wait) return;
		else $this->advancePhase();
	}
	
	public function advancePhase() // advanced to next phase and, if necessary, to next turn.
	{
		if ($this->phase==static::PHASE_LAST)
		{
			$this->phase=1;
			$this->round++;
		}
		else $this->phase++;
	}
	
	public function addBattler(Battler $battler) // adds new battler (a pre-made object) to the field.
	{
		$this->battlers[$battler->id]=$battler;
		$battler->field=$this;
	}
	
	public function addEffect($effect) // adds new effect to the chain. this can be a pre-made Effect object or an array of them.
	// This function should only be used before chain's resolution has begore. Otherwise use cascadeAddEffect.
	// In other words, Effect objects should use cascadeAddEffect, and other objects should use addEffect.
	{
		if (is_null($this->chain))
		{
			$this->chain=new EffectChain($this, $effect);
			$report=$this->chain->lastreport; // to bad "new" function cannot return another value beside new object.
		}
		else $report=$this->chain->add($effect);
		return $report;
	}
	
	public function cascadeAddEffect($effect) // adds a new effect or effects that have been spawn during a chain resolution.
	// this prevents calling "chainFormed" and following resolution again and again.
	{
		if (is_null($this->chain)) echo 'error!';
		else $report=$this->chain->cascadeAdd($effect);
		return $report;
	}
	
	public function chainFormed() // relates that a new chain has been started.
	{
		return $this->resolveChain(); // usually you'd like to resolve the chain immediately.
	}
	
	public function resolveChain() // tells chain to resolve.
	{
		if (!is_null($this->chain)) return $this->chain->progress();
		else return 'nochain';
	}
	
	public function resume()
	{
		if (!is_null($this->hooks->resume))
		{
			$report=$this->hooks->resume;
			if ($report=='wait') return $report;
		}
		// chain should not progress until all hooks are resolved!
		// otherwise some reactions may fail to execute properly.
		if (!is_null($this->chain))
		{
			$report=$this->resolveChain();
			if ($report=='wait') return $report;
		}
		return 'ok';
	}
	
	public function xml($data=null, $opions=array() )
	// this function outputs all battlefield data to xml. there should be some option to limit visibility of certain data...
	{
		if (isset($data['tag'])) $tag=$data['tag']; else $tag='battlefield';
		$result='<phase>'.$this->phase.'</phase>';
		foreach ($this->battlers as $battler)
		{
			$result.=$battler->xml($data);
		}
		$result.=$this->environment->xml($data);
		// STUB: input status should also be included.
		return "<$tag>".$result."</$tag>";
	}
}

interface LoggerInterface // some objects can print a log of what's happening.
{
	public function printLog($log);
}

trait toXML
{	
	public function stdxml($data, $add='')
	{
		if (isset($data['tag'])) $tag=$data['tag']; else $tag=$this->xmltag;
		$result='<id>'.$this->id.'</id><title>'.$this->title.'</title>';
		if ($add<>'') $result.=$add;
		return "<$tag>".$result."</$tag>";	
	}
}

}

#############
### STATS ###
#############
{
class Stats // Stats object contains entity's characteristics.
{
	public $owner=null; // reference; the entity that stats belong to.
	public $list=array(); // stats list, as in 'stat name' => value.
	public $counters=array('hp'); // an array of stat names what should have "max" value stored.
	public $limits=array();
	
	public $consultants=array(); // which objects should Stats consult (ask for modifications) when requested for a stat.
	
	public function __construct ($stats, $owner)
	{
		foreach ($this->counters as $counter)
		{
			if ( (array_key_exists($counter, $stats)) && (!array_key_exists('max'.$counter, $stats))) $stats['max'.$counter]=$stats[$counter]; // sets default max value for counter-type stats. default is the same as current.
		}
		$this->list=$stats;		
		$this->owner=$owner;
	}
	
	public function consult($name, $data, $who=null)
	// this function allows various conditions to modify the stat output. the procedure doesn't have to change with inheritance.
	// $name - stat name.
	// $data - request options; for example, are we getting the stat or modifying it? passed down to consultants.
	// $who - context, an entity that makes the request.
	{
		$data['name']=$name;
		if (is_array($this->consultants[$name]))
		{
			foreach ($this->consultants[$name] as $consultant)
			{
				$consultant->checkStat($this, $data, $who); // $data is passed as reference.
			}
		}
		return $data; // final corrections by consultants. they should be in format expected by getStat function.
		// also includes priod $data content.
	}
	
	public function getStat($name, $who=null)
	// this is the main function of this class. it returns the stat value after possible modification by 'consultants'.
	{
		$consult=$this->consult($name, array('action'=>'get'), $who);
		if (array_key_exists('value', $consult)) return $consult['value']; // if a specific value was returned, pass it.
		else return $this->list[$name]; // STUB
	}
	
	public function changeStat($name, $new, $who=null, $op=null)
	// this function changes stat's value permanently.
	{
		if ($op=='add') $new=$this->list[$name]+$new;
		elseif ($op=='mult') $new=$this->list[$name]*$new;
		elseif ($op=='bonus')  $new=$this->list[$name]*(1+$new/100);
		elseif ($op=='proc')  $new=$this->list[$name]*($new/100);		
		// these take the basic value because bonuses should affect that.
		
		$consult=$this->consult($name, array('action'=>'change', 'new'=>$new), $who);
		if (array_key_exists('value', $consult)) $new=$consult['value'];
		
		$new=$this->validateChange($name, $new);
		
		if ($new===$this->list[$name]) return; // no change.
		
		$this->list[$name]=$new;
		return $new;
	}
	
	public function getLimit($stat, $lim) // returns basic min and max of a stat.
	// $stat is stat's name
	// $lim is either 'min' or 'max'
	{
		$target=null;
		if ( (array_key_exists($stat, $this->limits))&&(array_key_exists($lim, $this->limits[$stat]))) $target=$this->limits[$stat][$lim];
		// specific limit from $limits array.
		elseif ( (array_key_exists('default', $this->limits))&&(array_key_exists($lim, $this->limits['default']))) $target=$this->limits['default'][$lim];
		// default limit from $limits array;
		if (is_null($target)) return null; // no limit found.
		elseif (is_numeric($target)) return $target; // numeric limit.
		elseif (($target=='max')&&(in_array($stat, $this->counters))) return $this->getStat('max'.$stat); // special instruction to set limit to max of the counter.
		else echo 'error! getLimit';
	}
	
	public function getMin($stat)
	{
		return $this->getLimit($stat, 'min');
	}
	
	public function getMax($stat)
	{
		return $this->getLimit($stat, 'max');
	}	
	
	public function validateChange($name, $new)
	// this function should check limits within which the stats can change.
	{
		if (array_key_exists($name, $this->limits))
		{
			$min=$this->getMin($name);
			if ((!is_null($min))&&($new<$min)) $new=$min;
			else
			{
				$max=$this->getMax($name);
				if ((!is_null($max))&&($new>$max)) $new=$max;
			}
		}
		return $new;
	}
	
	public function addConsultant($consultant, $stats)
	// counsultant is a condition or another object that may want to modify the output.
	{
		if (!is_array($stats)) $stats=array($stats);
		foreach ($stats as $stat)
		{
			$this->consultants[$stat][]=$consultant;
		}
	}
	public function removeConsultant($consultant)
	{
		foreach ($this->consultants as $stat=>$list)
		{
			foreach ($list as $key=>$ref)
			{
				unset($this->consultants[$stat][$key]);
			}
		}
	}
	
	public function xml($data=null)
	{
		if (isset($data['tag'])) $tag=$data['tag']; else $tag='stats';
		$result='';
		foreach ($this->list as $name=>$value)
		{
			$result.="<$name>".$this->getStat($name)."</$name>";
		}
		return "<$tag>".$result."</$tag>";
	}
}

interface Consultant
{
	public function checkStat($stats, &$data, $who);
}

trait StatReader
{
	public function getStat($name, $who=null) // rerouting the request to Stats object.
	{
		return $this->stats->getStat($name, $who);
	}
}
}

################
### BATTLERS ###
################
{
class Battler // an individual active participant of the battle.
{
	use toXML;
	use StatReader;
	public $xmltag='battler';
	
	public $id='';
	public $moves=array(); // moves are actions that can be performed by the battler.
	public $stats='Stats'; // an objects of this class name is created to contain stats.
	public $conditions=null; // a Conditions object; contains all conditions linked to the battler.
	public $field=null; // master battlefield reference.
	public $title='Debug'; // DEBUG
	public $owner=null; // a battler doesn't have to have an owner, maybe it's neutral.
	
	public function __construct($data)
	{
		static $nextid=1; // STUB! check if this works for battlers of different type.
		$this->id=$nextid; // each battler should have a unique id.
		$nextid++;
		
		$this->conditions=new Conditions($this);
		$class=$this->stats;
		$this->stats=new $class($data, $this);
	}
	
	public function xml($data=null) // waiting for new php "traits" functionality...
	{
		$add=$this->stats->xml($data).$this->conditions->xml($data);
		return $this->stdxml($data, $add);
	}
	
	public function addMove($move) // adds a new available action to the battler.
	{
		$this->moves[]=$move;
	}
}
}

#############
### HOOKS ###
#############
{
class Hooks
{
// an object of this class is used to notify various conditions that something has happed what they want to know about.
// it is used to modify effects or add new effects.

	public $field=null; // master battlefield.
	public $list=array(); // hooks list by id.
	public $byeffect=array(); // list of hook ids by effects that trigger them.
	public $resume=null; // stores the effect, hooks to which have not been processed completely, probably because spawned effect chain requires input.
	
	public function __construct($field)
	{
		$this->field=$field;
	}
	
	public function addHook($who, $effect, $if=null)
	// $effect should be a string class name. $who should be a reference to the object that will receive the hook.
	// $if is an assotiative array that must contain at least 'stage' element.
	// basic behavior is checking with $who object when $effect-instances progress to 'stage' stage.
	{
		if (!is_array($if)) $if=array(); // have at least empty array to avoid foreach errors.
		
		static $toarr=array('stage'); // some conditions should be forced to be arrays.
		// for example, 'stage' condition is a list of stages on which the hook can trigger.
		// It can also be a single string stage id, but in this case, it's convented to a single-element array.
		foreach ($toarr as $t)
		{
			if ((array_key_exists($t, $if))&&(!is_array($if[$t]))) $if[$t]=array($if[$t]);
		}
		if (count($this->list)==0) $id=1;
		else $id=(int)(max(array_keys($this->list)))+1; // can't use count(), because hooks may be removed, but the remaining keys will stay the same.
		
		$this->list[$id]=array('call'=>$who, 'effect'=>$effect, 'if'=>$if);
		foreach ($if['stage'] as $stage)
		{
			$this->byeffect[$stage][$effect][$id]=$id;
		}
	}
	
	public function removeHook ($id)
	// conditions should be neat and should remove their hooks when they're not needed anymore.
	{
		foreach ($this->list[$id]['stage'] as $stage)
		{
			unset($this->byeffect[$stage][$this->list[$id]['effect']][$id]);
		}
		unset ($this->list[$id]);
	}
	
	public function checkHooks($effect)
	// this function checks if there are hooks for this state of an effect.
	// the function itself is called by Effects when they progress. it's part of their basic behavior.
	// checkHooks may be called a few times during the same stage, in case "progress" function repeats.
	// in any case, it's called after 'ok' or 'repeat' resolution, before stage increment.
	{
		if ($effect==='resume') { $effect=$this->resume; $resuming=1; }
		else $resuming=0;
		// special code 'resume' makes Hooks object retriever the Effect in question from a variable.
		// this also prevents it to advaned $eventid, so that called effects would know they already processed that.
		
		$stage=$effect->stage;
		if (!is_array($this->byeffect[$stage])) return; // no hooks for this stage.
		$wait=0;
		static $eventid=0;
		// unique event id to prevent multiple fires for single event.
		// the id is unique for each fire subscription, not for each cause of fire.
		$fired=$resuming; // if the hooks process is resumed after 'wait' report, then some hook must have beed fired.
		
		foreach ($this->byeffect[$stage] as $class=>$ids)
		{
			if ($effect instanceof $class) // also works for interfaces!
			// that's a lot of cycles, but most of them should be skipped after this check.
			{
				foreach ($ids as $id) // these are hook ids
				{
					$if=$this->list[$id]['if'];
					unset($if['stage']); // the stage check is actually redundant because we only check for hooks that have same stage as effect.
					$call=$this->checkIf($effect, $if);
					if ($call)
					{
						if ($fired==0) $eventid++;
						$fired=1;
						$report=$this->list[$id]['call']->catchHook($effect, $eventid);
						if ($report=='wait') { $this->resume=$effect; return 'wait'; }
						// actually, 'wait' result it unlikely, because possible new effects start at INIT stage and don't progress yet.
						// they will progress in EffectChain's next pass with others.
					}
					// we don't sent which exact hook was caught because we only have their automatically set ids.
					// the $who object should figure that out.
				}
			}
		}
		
		if ($resuming) $this->resume=null; // if the function was finish without 'wait' resolution, which would return 'wait' before this line.
	}
	
	public function checkIf($effect, $if, $value='')
	{
		$call=true;	
		if (is_array($if))
		{
			foreach ($if as $i => $val)
			{
				$check=$this->checkIf($effect, $i, $val);
				if (!$check) { $call=false; break; }
			}
		}
		else
		{
			$stage=$effect->stage;
			if ($if=='user') // compares effect's user to a specific battler reference
			{
				if (($stage==EffectChain::STAGE_INIT)&&($effect->initdata['user']<>$value)) $call=false;
				elseif (($stage==EffectChain::STAGE_GATHER)&&($effect->gather['user']<>$value)) $call=false;
			}
		}
		return $call;
	}
	
	public function resume()
	{
		if (!is_null($this->resume)) $this->checkHooks('resume');
	}
}

trait HookCatcher
{
	public $caught=array();
	
	public function catchHook($effect, $eventid)
	{
		if (in_array($eventid, $this->caught, 1)) return 'caught';
		$caught[]=$eventid;
		$report=$this->catchedHook($effect);
		return $report;
	}
}

}

##################
### CONDITIONS ###
##################
{
class Conditions
// 'Conditions' object handles a set of 'Condition' objects, just like EffectChain handles a set of Effects.
// 'Condition' objects are lasting special qualities and after-effects. Only conditions survive after EffectChain resolution.
// Conditions set are usually attached to a battler or the battlefield.
{
	public $list=array();
	public $owner=null;
	public $field=null;
	
	public function __construct($owner)
	{
		$this->owner=$owner; // usually battler or battlefield.
		if ($owner instanceof Battlefield) $this->field=$owner;
		else $this->field=$owner->field;
	}
	
	public function add($type, $arg1=null, $arg2=null, $arg3=null)
	{
		$classname='cnd'.$type; // all condition classes should start with 'cnd'! that way we can autoload them and not mix up with attacks of the same name.
		if (is_null($arg1)) $new=new $classname($this->owner);
		elseif (is_null($arg2)) $new=new $classname($this->owner, $arg1);
		elseif (is_null($arg3)) $new=new $classname($this->owner, $arg1, $arg2);
		else $new=new $classname($this->owner, $arg1, $arg2, $arg3); // up to three arguments are allowed for creating new Condition object.
		
		// this block handles how Conditions relate to each other.
		// the resolution of each other Condition about the new one is placed in $stack variable.
		// 'forget' means new Condition should not be made.
		// 'replace' means that the old condition should be replaced by a new one.
		// in case no other Condition objects or corrects stacking, new Condition is added to the end of the list.
		// 			this block is probably goind to change to expand stacking behavior (such as extending timer) or behavior on creating/removing conditions.
		foreach ($this->list as $id=>$cond)
		{
			$stack=$cond->Stack($new);
			if ($stack==='forget') return;
			elseif($stack==='replace') { $list[$id]=$cond; return; }
		}
		$this->list[]=$new;
	}
	
	public function tick()
	// this function is analogous to progress() function of Battlefield.
	// $phase is current Battlefield phase, such as 'after_turn'.
	{
		foreach ($this->list as $id=>$cond)
		{
			$report=$cond->tick();
			// each Condition has tick() function. it usually applies condition's effect and decreases timer.
			// tick() relates current phase. many conditions only tick on certain turn phases, such as 'after_turn'.
			if ($report=='remove') unset($list[$id]);
			elseif ($report=='wait') return $report;
		}
	}
	
	public function xml($data=null)
	{
		if (isset($data['tag'])) $tag=$data['tag']; else $tag='conditions';	
		$result='';
		foreach ($this->list as $condition)
		{
			$result.=$condition->xml($data);
		}
		return "<$tag>".$result."</$tag>";
	}
	
	public function __destruct()
	{
		$this->owner=null;
		$this->field=null;
	}
}
abstract class Condition
{
	public $timeleft=666; // this var stores remaining ticks before the condition is out. 666 is code for eternal.
	public $tickphase=0, $ticked=0, $expired=0;
	public $type='';
	public $owner=null, $field=null;
	
	public function __construct($owner, $type='')
	{
		$this->owner=$owner;
		$this->field=$ownner->field;
		$this->type=$type;
	}
	
	public function tick()
	// this function applies condition's effect and decreases its timer.
	// false return value means that the condition has ended and should be removed.
	{
		if ($this->expired) return 'expired';
		$phase=$this->field->phase;
		$round=$this->field->round;
		if ($this->tickphase==0) return 'skip';
		if ($this->ticked==$round) return 'ticked';
		if ($phase==$this->tickphase)
		{
			$this->ticked=$round;
			if ($this->timeleft==666) return 'ok';
			if ($this->timeleft==1) { $this->expire(); return 'remove'; }
			$this->timeleft--;
			return 'ok';
		}
		return 'skip';
	}
	
	public function expire()
	{
		$this->expired=1;
		$this->timeleft==0;
	}
	
	public function Stack(Condition $new)
	// this function resolves how various conditions should stack. it's called when a new Condition is made. return values:
	// 'ignored' - no relation. proceed.
	// 'forget' - erase new condition.
	// 'replace' - replace this condition with new one.
	// 		this function is likely to be rewritted in future if we will need multityped conditions.
	{
		if ($this->type<>$new->type) return 'ignored'; // default: different-typed conditions don't affect each other.
		elseif ($this->timeleft>=$new->timeleft) return 'forget'; // default: if new condition has shorter timer than same-typed condition - forget new condition.
		else return 'replace'; // default: if new condition has longer timer, let it replace current same-typed condition.
	}
	
	public function __destruct()
	{
		$this->owner=null;
		$this->field=null;		
	}

	public function xml($data=null)
	{
		if (isset($data['tag'])) $tag=$data['tag']; else $tag='condition';	
		$name=get_class($this);
		if (substr($name, 0,3)=='cnd') $name=substr($name, 3);
		$result='<title>'.$name.'</title>';
		return "<$tag>".$result."</$tag>";
	}
}

abstract class regularCondition extends Condition
{
	public function tick()
	{
		$report=parent::tick();
		if (($report=='ok')||($report=='remove'))
		{
			$this->init();
		}
		return $report;
	}
	
	public function init($source=null)
	{
		if (is_null($source)) $source=$this->owner;
		$report=$self->field->addEffect(new initCondition(array('source_condition'=>$this, 'source'=>$source)));
		// Regular condition begins with 'initCondition' effect to allow for condition-changing effects.
		return $report;
	}
	
	abstract function createConditionEffect($initdata, $gather, $result); // the data is related from initCondition process.
	// some conditions can spawn cascades of effects.
	
	public function trackEffects($effect) { } // this function is called while spawned effects reach various stages of progress.
	// it's called after 'ok' or 'repeat' resolution of effect's progress() function.
	// the condition can chage effect's parameters, such as gather and result, or react to certain parameter combination.	
}

}

###############
### ACTIONS ###
###############
{
abstract class Action
// actions are actual moves that battlers can attempt at battlefield.
// for example, moving from square to square, making attacks, using abilities.
{
	public function init($battler) // this function is called first when the action is attempted.
	{
		$report=$battler->field->addEffect(new initAction(array('used_action'=>$this, 'user'=>$battler)));
		// Action begins with 'initAction' effect to allow for action-changing effects and conditions.
		// for example, 'confused' condition has 50% chance of making its victim attack itself instead of making the action they wanted.
		return $report;
	}
	
	abstract function createActionEffect($initdata, $gather, $result); // the data is related from initAction process.
	// some actions can spawn cascades of effects, such as first checking if the attack has hit and then spawning the damage effect.
	
	public function trackEffects($effect) { } // this function is called while spawned effects reach various stages of progress.
	// it's called after 'ok' or 'repeat' resolution of effect's progress() function.
	// the action can chage effect's parameters, such as gather and result, or react to certain parameter combination.
}
}

###############
### EFFECTS ###
###############
{
// Effect chain is resolved before any other events occur. While the chain is resolved, all effects are added to this specific chain and are part of it.
// Sometimes the effect chain can be 'frozen' for player's input.
// If a lasting effect is required beyond chain's scope, it should be created as a condition.

class EffectChain
{
	const STAGE_INIT=0, STAGE_GATHER=1, STAGE_FORM=2,STAGE_EXECUTE=3, STAGE_END=4;
	public $list=array(); // array for storing all effects. their relations are governed by effects' properties.
	public $field=null; // master battlefield's reference.
	public $logs=array(); // this lists various changes that occured in battlefield.
	// the log is used both narrate combat events and change visual data, such as health bars.
	// some log items are not sent to the user (or specific user), because they are hidden from them.
	public $stage=self::STAGE_INIT;
	public $lastreport='';
	
	// init - the effect is created. usually no functions are called, though hooks may trigger.
	// gather - gathers data necessary for the next stage, such as user's stats.
	// form - establishes random values, picks targets.
	// execute - applies effect to target(s).
	
	public function __construct(Battlefield $field, $effect)
	// to create an effect chain, we have to have an environment and at least one effect.
	{
		$this->field=$field;
		$field->chain=$this;
		$this->add($effect);
	}
	
	public function add ($effect, $rec=0)
	// adds a pre-made Effect object (or an array of them) to the chain's list.
	{
		if (is_array($effect))
		{
			foreach ($effect as $e)
			{
				$this->add($e, 1);
			}
			if ($rec==0) return $this->field->chainFormed();
			else return 'cascading';
		}
		else
		{
			if (count($this->list)>0) end($this->list)->dependent[]=$effect;
			$this->list[]=$effect;
			$effect->chain=$this;
			if ($rec==0) return $this->field->chainFormed();
			else return 'cascading';
		}
	}
	
	public function cascadeAdd($effect)
	{
		$report=$this->add($effect, 1);
		return $report;
	}
	
	public function progress($targetstage=self::STAGE_END)
	// all effects are asked to progress to the next stages (to end by default). the routine is not finished until all effects report that they have progressed from the current stage.
	// if new effects are added in the course of the progress, they are asked and expected the same.
	// for this purpose, 'foreach' is repeated until _every_ effect report the required stage in a single cycle.
	{
		if ($targetstage<=$this->stage) $report='done'; // if expected stage (or later stage) is already achieved.
		elseif ($targetstage>$this->stage+1) // if expected stage is more than one stage away, do it one by one.
		{
			for ($target=$this->stage+1; $target<=self::STAGE_END; $target++)
			{
				$report=$this->progress($target);
				if ($report=='wait') break;
			}
		}
		else // if expected stage is just one stage away
		{
			if ($this->stage==self::STAGE_END) return; // if current stage is 'end', return anyway.
			$done=false;
			while (!$done)
			{
				$done=true; $wait=true;
				// if $done is true at the end of the cycle, then the cycle breaks.
				// it should not be set true until all effects report expected stage BEFORE progress() call. this logic guarantees no new changes.
				// if $wait is true, return 'wait' resolution.
				// if any effect return something other than 'wait', then $wait sets to false;
				foreach ($this->list as $effect) 
				{
					if ($effect->stage <= $this->stage)
					{
						$done=false;
						$report=$effect->progress($targetstage);
						//echo 'progressing '.get_class($effect).' from stage '.$effect->stage.': '.$report.'<br>';						
						if ($report=='stop') { $report='stop'; break; }
						if ($report<>'wait') $wait=false;
					}
					// each effect that has less than expected stage is asked for maximum progress.
					// also, $this->list can be appended in course of progress.
				}
				if ($wait) break;
			}
			if ($report=='stop') { }
			elseif (($done)&&($this->stage==self::STAGE_END)) $report='end';
			elseif ($done) { $this->stage++; $report='done'; }
			elseif ($wait) $report='wait';
			// when each effect progresses to expected stage or beyond, advance chain's stage.
			
			$this->lastreport=$report;
			return $report;
		}
	}
	
	public function log($entry, $initdata=null)
	// this function adds an entry to the effects log.
	{
		$entry['source']=$initdata;
		$this->logs[]=$entry;
	}
	
	public function fullLog()
	// this function returns an array of strings describing the log of the effect chain.
	// this only describes some effects because not every change in battlefield should be narrated.
	{
		$full=array();
		foreach ($this->logs as $log)
		{
			$render=$log['source']->printLog($log);
			if ($render<>'') $full[]=$render;
		}
		return $full;
	}
}

abstract class Effect implements LoggerInterface
{
	public $chain=null; // reference to the master chain. it's requred for spawning new effects, such as "Earthquake" spawning RawDamage for all targets.
	// $chain is set by the master chain when adding the effect to it.
	public $stage=EffectChain::STAGE_INIT; // current stage. stage order from the EffectChain is used.
	public $iteration=1;
	
	public $initdata=null; // an array of references to sources of the effect. elements are keyed 'user', 'used_action', 'triggered_condition', etc...
	public $gather=array(); // this is where all variables required for effect's logic are stored after they are retrieved.
	public $result=array(); // this is where all end data of the effect is stored after it has formed.
	
	public $dependent=array(); // the effect won't progress until these effect are at end stage.
	
	public function __construct($initdata=null)
	{
		$this->initdata=$initdata;
	}
	
	public function progress()
	// this function is called when effect is expected to make progress, such as resolve random amount of damage to concrete number.
	// default behaviour: execute on execute or just change stage on anything else.
	// return codes:
	// 'wait' - can't progress until some other effects are resolved.
	// 'repeat' - resolved ok, but requires repetition.
	// 'ok' - resolution was ok and the stage has advance.
	// 'stop' - error.
	{
		foreach ($this->dependent as $key=>$dep)
		{
			if ($dep->stage<EffectChain::STAGE_END) return 'wait';
			else unset($this->dependent[$key]); // if the effect that this one depends upon is resolved, remove it.
		}
		if ($this->stage==EffectChain::STAGE_GATHER) $report=$this->gather();
		elseif ($this->stage==EffectChain::STAGE_FORM) $report=$this->form();
		elseif ($this->stage==EffectChain::STAGE_EXECUTE) $report=$this->execute();
		else $report='ok'; // init and end stages don't call functions, so they can't return error.

		if (is_null($report)) { echo 'oops!'; var_dump($this); exit; }
		
		if (in_array($report, array('ok', 'repeat'), 1))
		// 'repeat' means that the stage requires several passes of progress calls.
		// for example, the first pass gathers target reference and waits to let effects correct it;
		// the second pass gathers stats from the target.
		{
			// commands in case resolution went ok.
			if ($this->initdata['used_action']) $this->initdata['used_action']->trackEffects($this); // this allows moves to react to effects' stage changes.
			$hookreport=$this->chain->field->hooks->checkHooks($this); // this allows conditions and other non-related objects to react.
			if ($hookreport=='wait') $report='wait'; // only 'wait' resolution transferes to Effect progress.
		}
		
		if ($report=='ok') { $this->stage++; $this->iteration=1; } // only on 'ok' resolution the stage advances.
		else $this->iteration++; // effects should always check for iteration, because effects that don't usually have 'repeat' resolution may still get 'wait' from hooks.
		return $report;
	}

	public function gather()
	{
		if ($this->iteration==1) $this->gather=$this->initdata;
		return 'ok';
	}
	public function form()
	{
		if ($this->iteration==1) $this->result=$this->gather;	
		return 'ok'; 
	}		
	public function execute()
	{
		return 'ok';
	}
	// actual effects will have very different logic for each these steps. there are few conventions, though:
	// each inherited function calls the parent's function first, just in case we'd like to add something here.
	
	public function log($entry)
	// this  function relates adding a log entry to the master chain.
	{
		$chain->log($entry);
	}

	static $log_formats=array('default'=>'Совершено действие: {action}.');
	public function printLog($log)
	// this function returns the text description of some aspect of the effect
	{
		if (self::$log_formats[$log['action']]<>'') $format=self::$log_formats[$log['action']];
		else $format=self::$log_formats['default']; // DEBUG
		// else return '';
		
		return format($format, $log);
	}
}

abstract class targetedEffect extends Effect
// this covers effects that have to have one or more targets (some effect affect battlefield, not targets).
// it only allows to store target list. the gather array doesn't have different subarrays for different targets.
// in case the effect has to affect targets individually, it should split to single-targetted effects in execute stage.
// NOTE: this probably is going to be a trait.
{
	public $targets=array(); // multiple targets are allowed for all effects, although in many cases they will split in multiple effects.
	
	public function __construct($initdata=null, $targets=null)
	{
		parent::__construct($initdata);
		if (is_array($targets)) $this->targets=$targets;
		else $this->targets=array($targets);
	}		
}

class StatChange extends targetedEffect
{
	public function __construct($initdata, $targets, $name, $change)
	{
		$initdata['name']=$name; // stat name
		$initdata['change']=$change; // new value. this effect only sets specific, permanent value.
		parent::__construct($initdata, $targets);
	}
	
	public function execute()
	{
		$report=parent::execute();
		if (($report!=='stop')&&($this->iteration==1))
		{
			foreach ($this->targets as $target)
			{
				$target->stats->changeStat($this->gather['name'], $this->gather['change'], $this);
			}
		}
		return $report;
	}
}

class initAction extends Effect
// this is a separate class because some effects make battler abandon an attack or make a different one.
// if such effect triggers, then battler's action is resolved without even touching the move that he was supposed to make.
{	
	public function execute()
	{
		$report=parent::execute();
		if (($report!=='stop')&&($this->iteration==1))
		{
			if (isset($this->gather['used_action'])) // the move may be erased by another effect before it launched.
			{
				$e=$this->gather['used_action']->createActionEffect($this->initdata, $this->gather, $this->result);
				// 'createActionEffect' makes an actual move effect, such as damage. it can also make an array of effects.
				// Input and other such effects are created at this point. Action use can still be canceled based on input.
				if ($e) $this->gather['user']->field->cascadeAddEffect($e);
			}
		}
		return $report;
	}
}

class initCondition extends Effect
{	
	public function execute()
	{
		$report=parent::execute();
		if (($report!=='stop')&&($this->iteration==1))
		{
			if (isset($this->gather['source_condition']))
			{
				$e=$this->gather['source_condition']->createConditionEffect($this->initdata, $this->gather, $this->result);
				if ($e) $this->gather['source_condition']->field->cascadeAddEffect($e);
			}
		}
		return $report;
	}
}

abstract class Input extends Effect
{
	public $input_type='basic', $completed=false;
	
	public function xml($options=null)
	// this function should be overriden
	{
		if ($options['tag']<>'') $tag=$options['tag']; else $tag='input';
		$result='<type>'.$this->input_type.'</type>';
		if ($options['add']<>'') $result.=$options['add'];
		return "<$tag>".$result."</$tag>";
	}
	
	public function gather()
	{
		$report=parent::gather();
		if ($report!=='stop')
		{
			$this->validate();
			if (!$this->completed) $report='wait';
		}
		return $report;
	}
	
	abstract function validate($data=''); // $data is optional array of inputted data.
	// this function validates inputted data, puts verified data into $gather and checks if $gather is complete.
	// it sets $completed to true if input is complete.
	// TODO: some feedback on what is left to input or why some data wasn't legal? probably with log functions.
	
	static $error_formats=array('default'=>'Произошла ошибка: {error}.');
	static function printError($error)
	{
		if (self::$error_formats[$error['action']]<>'') $format=self::$error_formats[$error['action']];
		else $format=self::$error_formats['default']; // DEBUG
		// else return '';
		
		return format($format, $error);
	}
}

class PickBattlers extends Input
{
	public $input_type='targets';
	
	public function validate($data='')
	{
		
	}
}

}

#############
### LOGS ####
#############

{
class Logger
{

}
}

########################
### SAVE & INTERFACE ###
########################
{
function save($id, $field)
{
	$query="UPDATE combat_save SET save_data='".serialize($field)."' WHERE combatID=$id";
	$result=mysql_query($query);
}

function load($id)
{
	$query="SELECT save_data FROM combat_save SET WHERE combatID=$id";
	$result=mysql_fetch_assoc(mysql_query($query));
	$field=unserialize($result['save_data']);
	return $field;
}
}
?>