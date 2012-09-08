<?
define('GOD', true);
include('connect.php');
include('combat_def.php');
include('monster_def.php');
header('Content-type: text/xml');

$battle=1; // DEBUG
// here should be some check that the battle id is legal.

//DEBUG
$field=new Battlefield(2);
$poke1=new Monster(array('att'=>100, 'def'=>50, 'spatt'=>30, 'spdef'=>20, 'hp'=>200, 'speed'=>50));
$field->addBattler($poke1);
$move=new Attack(array('power'=>2500, 'accuracy'=>50, 'PP'=>5, 'type'=>'raw', 'category'=>'physical'));
$poke1->addMove($move);
$poke1->conditions->add('StatMod', 'att', 5);
$move->init($poke1);

if ($message==='board') // DEBUG
{
//	$field=load($battle); // DEBUG
	echo '<?xml version="1.0" encoding="windows-1251" standalone="yes"?>';
	?>
	<response>
	<method><? echo $message; ?></method>
	<result>
	<?
	echo $field->xml();
	?>
	</result>
	</response>
	<?
}
?>