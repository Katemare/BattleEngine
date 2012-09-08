<link type="text/css" href="combat.css" rel="STYLESHEET" />
<script type="text/javascript" src="request2.js" language="JavaScript"></script>
<?
define('GOD', true);
include('connect.php');
include('combat_def.php');
include('monster_def.php');

echo 'ready<br>';

/* $field=new Battlefield();
$poke1=new Monster(array('att'=>100, 'def'=>50, 'spatt'=>30, 'spdef'=>20, 'hp'=>200, 'speed'=>50));
$field->addBattler($poke1);
$poke1->conditions->add('StatMod', 'att', 1);

//$poke1->conditions->add('Toxic');
//$poke1->tick();

$effect=new Damage(array('user'=>$poke1), $poke1, 100, 'raw');
$field->addEffect($effect);
$field->chain->progress();
echo implode('<br>', $field->chain->fullLog());

echo htmlspecialchars($field->xml()); exit; */
?>

<div id="battlefield"></div>
<script>
document.write('ok');
var $field=document.getElementById('battlefield');
var $battlers=new Object();

function Battler($id, $name, $maxhp, $hp)
{
	this.id=$id;
	$battlers['b'+$id]=this;
	
	this.element=document.createElement('div'); this.element.god=this;
	
	this.titlediv=document.createElement('div');
	this.element.appendChild(this.titlediv);
	this.__defineGetter__('name', function()
	{
		return this.nick;
	} );
	this.__defineSetter__('name', function($name)
	{
		this.nick=$name;
		this.titlediv.innerHTML=$name;
	} );
	this.name=$name;
	
	this.maxhp=$maxhp;
	this.meter=new Meter($maxhp);
	this.element.appendChild(this.meter.element);	
	this.__defineGetter__('hp', function()
	{
		return this.curhp;
	} );
	this.__defineSetter__('hp', function($hp)
	{
		this.curhp=$hp;
		this.meter.value=$hp;
	} );
	this.hp=$hp;
	
	$field.appendChild(this.element);
	
	this.pickable=true; // DEBUG
	this.pick=false;
	this.color='transparent';
	this.picked_color='lightblue';
	
	this.element.onclick=function($e)
	{
		if (!this.god.pickable) return;
		this.god.picked=!this.god.picked;
	}
	this.__defineGetter__('picked', function()
	{
		return this.pick;
	} );
	this.__defineSetter__('picked', function($pick)
	{
		this.pick=$pick;
		if (this.pick) this.element.style.backgroundColor=this.color;
		else this.element.style.backgroundColor=this.picked_color;
	} );	
} 

function Meter($max)
{
	this.max=$max;
	if (typeof(arguments[1])!='undefined') this.len=arguments[1];
	else this.len=300;
	
	this.element=document.createElement('div'); this.element.className='meter';
	this.element.style.width=this.len;
	this.curelement=document.createElement('div'); this.curelement.className='meter_current';
	this.element.appendChild(this.curelement);
	
	this.__defineGetter__('value', function()
	{
		return this.cur;
	} );
	this.__defineSetter__('value', function($new)
	{
		this.cur=$new;
		this.curelement.style.width=(this.cur/this.max)*this.len;
	} );
	this.value=$max;
	
}

function Move($owner, $code, $title)
{
	this.code=$code;
	this.owner=$owner;
	
	this.element=document.createElement('div');
	
	this.titlediv=document.createElement('div');
	this.element.appendChild(this.titlediv);
	
	this.__defineGetter__('title', function()
	{
		return this.name;
	} );
	this.__defineSetter__('title', function($title)
	{
		this.name=$title;
		this.titlediv.innerHTML='<a href="javascript:sendAttack(\''+this.owner+'\',\''+this.code+'\')>'+$title+'</a>';
	} );
}

function fcd($obj, $tag)
{
	try
	{
	return $obj.getElementsByTagName($tag)[0].firstChild.data;
	} catch(e)
	{
	return '';
	}
}

function parse_received($msg, $method)
{
	// $msg.getElementsByTagName('tag')
	// fcd($msg, 'tag')
	
	if ($method=='board')
	{
		var $fieldtag=$msg.getElementsByTagName('battlefield')[0];
		var $blist=$fieldtag.getElementsByTagName('battler'), $btag, $b, $stats;
		
		for (var $x=0; $x<$blist.length; $x++)
		{
			$btag=$blist[$x];
			$stats=$btag.getElementsByTagName('stats')[0];
			$b=new Battler(fcd($btag, 'id'), fcd($btag, 'title'), fcd($stats, 'maxhp'), fcd($stats, 'hp'));
		}
	}
}
makeRequest('arena.php', 'board');

</script>