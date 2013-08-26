<?php
/*
Planning Biblio, Version 1.5.2
Licence GNU/GPL (version 2 et au dela)
Voir les fichiers README.txt et COPYING.txt
Copyright (C) 2011-2013 - Jérôme Combes

Fichier : planning/poste/index.php
Création : mai 2011
Dernière modification : 21 août 2013
Auteur : Jérôme Combes, jerome@planningbilbio.fr

Description :
Cette page affiche le planning. Par défaut, le planning du jour courant est affiché. On peut choisir la date voulue avec le
calendrier ou les jours de la semaine.

Cette page est appelée par la page index.php
*/

require_once "class.planning.php";
require_once "planning/postes_cfg/class.tableaux.php";
include_once "absences/class.absences.php";

echo "<div id='planning'>\n";

include "fonctions.php";

// Initialisation des variables
$verrou=false;
//		------------------		DATE		-----------------------//
$date=isset($_GET['date'])?$_GET['date']:null;
if(!$date and array_key_exists('PLdate',$_SESSION))
	$date=$_SESSION['PLdate'];
elseif(!$date and !array_key_exists('PLdate',$_SESSION))
	$date=date("Y-m-d");	
$_SESSION['PLdate']=$date;
$dateFr=dateFr($date);
$d=new datePl($date);
$semaine=$d->semaine;
$semaine3=$d->semaine3;
$jour=$d->jour;
$dates=$d->dates;
$datesSemaine=join("','",$dates);
$j1=$dates[0];
$j2=$dates[1];
$j3=$dates[2];
$j4=$dates[3];
$j5=$dates[4];
$j6=$dates[5];
$j7=$dates[6];
$dateAlpha=dateAlpha($date);
//		------------------		FIN DATE		-----------------------//
//		------------------		TABLEAU		-----------------------//
$t=new tableau();
$t->fetchAllGroups();
$groupes=$t->elements;

// Multisites : la variable $site est égale à 1 par défaut. Elle prend la valeur GET['site'] si elle existe, sinon la valeur de la SESSION ['site']
$site=isset($_GET['site'])?$_GET['site']:null;
if(!$site and array_key_exists("site",$_SESSION['oups'])){
  $site=$_SESSION['oups']['site'];
}
$site=$site?$site:1;
$_SESSION['oups']['site']=$site;

$db=new db();
$db->select("pl_poste","*","`date` IN ('$datesSemaine') AND `site`='$site'");
$pasDeDonneesSemaine=$db->result?false:true;
//		------------------		FIN TABLEAU		-----------------------//
global $idCellule;
$idCellule=0;
//		------------------		Vérification des droits de modification (Autorisation)	------------------//
$autorisation=false;
if($config['Multisites-nombre']>1){
  if(in_array((300+$site),$droits)){
    $autorisation=true;
  }
}
else{
  $autorisation=in_array(12,$droits)?true:false;
}
//		-----------------		FIN Vérification des droits de modification (Autorisation)	----------//

//		---------------		changement de couleur du menu et de la periode en fonction du jour sélectionné	---------//
$class=array('menu','menu','menu','menu','menu','menu','menu','menu','menu');
$class2=array('menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2','menu2');
 
switch($jour){
  case "lun":	$jour3="Lundi";		$periode2='semaine';	$class[0]='menuRed';	break;
  case "mar":	$jour3="Mardi";		$periode2='semaine';	$class[1]='menuRed'; break;
  case "mer":	$jour3="Mercredi";	$periode2='semaine';	$class[2]='menuRed'; break;
  case "jeu":	$jour3="Jeudi";		$periode2='semaine';	$class[3]='menuRed';	break;
  case "ven":	$jour3="Vendredi";	$periode2='semaine';	$class[4]='menuRed'; break;
  case "sam":	$jour3="Samedi";	$periode2='samedi';	$class[5]='menuRed'; break;
  case "dim":	$jour3="Dimanche";	$periode2='samedi';	$class[6]='menuRed'; break;
}
	
//-----------------------------			Verrouillage du planning			-----------------------//
$db_verrou=new db();
$db_verrou->query("SELECT * FROM `{$dbprefix}pl_poste_verrou` WHERE `date`='$date' AND `site`='$site';");
if($db_verrou->result){
  $verrou=$db_verrou->result[0]['verrou2'];
  $perso=nom($db_verrou->result[0]['perso']);
  $perso2=nom($db_verrou->result[0]['perso2']);
  $date_validation=dateFr(substr($db_verrou->result[0]['validation'],0,10));
  $heure_validation=substr($db_verrou->result[0]['validation'],11,5);
  $date_validation2=dateFr(substr($db_verrou->result[0]['validation2'],0,10));
  $heure_validation2=substr($db_verrou->result[0]['validation2'],11,5);
  $validation2=$db_verrou->result[0]['validation2'];
}
//	---------------		FIN changement de couleur du menu et de la periode en fonction du jour sélectionné	--------------------------//

//	Selection des messages d'informations
$db=new db();
$db->query("SELECT * FROM `{$dbprefix}infos` WHERE `fin`>='$date' ORDER BY `debut`,`fin`;");
$messages_infos=null;
if($db->result){
  foreach($db->result as $elem){
    $messages_infos[]=$elem['texte'];
  }
  $messages_infos=join($messages_infos," - ");
}

//		---------------		Affichage du titre et du calendrier	--------------------------//
echo "<div id='divcalendrier' class='text'>\n";

echo "<form name='form' method='get' action='#'>\n";
echo "<input type='hidden' id='date' name='date' />\n";
echo "</form>\n";

echo "<table id='tab_titre'>\n";
echo "<tr><td><div class='noprint'>\n";
?>
<iframe id='cal_iframe' src='include/calendrier.php?champ=pl_poste' frameborder='0' scrolling='no'></iframe>
<?php
echo "</div></td><td class='titreSemFixe'>\n";
echo "<div class='noprint'>\n";

switch($config['nb_semaine']){
  case 2 :	$type_sem=$semaine%2?"Impaire":"Paire";	$affSem="$type_sem ($semaine)";	break;
  case 3 : 	$type_sem=$semaine3;			$affSem="$type_sem ($semaine)";	break;
  default :	$affSem=$semaine;	break;	
}
echo "<b>Semaine $affSem</b>\n";
echo "<div id='semaine_planning'<b>Du ".dateFr($j1)." au ".dateFr($j7)."</b>\n";
echo "</div>\n";
echo "<div id='date_planning'>Planning du $dateAlpha";
if(jour_ferie($date)){
  echo " - <font id='ferie'>".jour_ferie($date)."</font>";
}
echo <<<EOD
  </div>
  <table class='noprint' id='tab_jours'><tr valign='top'>
    <td><a href='index.php?date=$j1'  class='{$class[0]}' >Lundi</a> / </td>
    <td><a href='index.php?date=$j2'  class='{$class[1]}' >Mardi</a> / </td>
    <td><a href='index.php?date=$j3'  class='{$class[2]}' >Mercredi</a> / </td>
    <td><a href='index.php?date=$j4'  class='{$class[3]}' >Jeudi</a> / </td>
    <td><a href='index.php?date=$j5'  class='{$class[4]}' >Vendredi</a> / </td>
    <td><a href='index.php?date=$j6'  class='{$class[5]}' >Samedi</a></td>
EOD;
if($config['Dimanche']){
    echo "<td align='center'> / <a href='index.php?date=$j7'  class='".$class[6]."' >Dimanche</a> </td>";
}
echo "</tr></table>";
  
if($config['Multisites-nombre']>1){
  echo "<h3>{$config['Multisites-site'.$site]}</h3>";
}
//	---------------------		Affichage des messages d'informations		-----------------//
echo "<div id='messages_infos'>\n";
echo "<marquee>\n";
echo $messages_infos;
echo "</marquee>\n";
echo "</div>";

echo "</td><td id='td_boutons'>\n";

//	----------------------------	Récupération des postes		-----------------------------//
$postes=Array();
$db=new db();
$db->query("SELECT * FROM `{$dbprefix}postes` ORDER BY `id`;");
if($db->result){
  foreach($db->result as $elem){
    $postes[$elem['id']]=Array("nom"=>$elem['nom'],"etage"=>$elem['etage'],"obligatoire"=>$elem['obligatoire']);
  }
}
//	-----------------------		FIN Récupération des postes	-----------------------------//

echo "<div id='validation'>\n";
if($autorisation){
  if($verrou){
    echo "<font><u>Validation</u><br/>$perso2 $date_validation2 $heure_validation2<br/></font>\n";
    echo "<a href='index.php?page=planning/poste/verrou.php&amp;date=$date&amp;verrou2=0&amp;site=$site'><img id='deverrou' src='img/verrou.jpg' alt='verrou2=0' /></a>\n";
  }
  else{
    echo "<a href='index.php?page=planning/poste/verrou.php&amp;date=$date&amp;verrou2=1&amp;site=$site'><img id='verrou' src='img/deverrou.jpg' alt='verrou1=1' /></a>\n";
  }
}

if($autorisation){
  echo "<a href='javascript:popup(\"planning/poste/enregistrer.php\",500,270);'><img src='img/save.jpg' alt='Enregistrer'/></a>&nbsp;";
  if(!$verrou){
    echo "<a href='javascript:popup(\"planning/poste/importer.php\",500,270);'><img src='img/open.jpg' alt='Importer'/></a>&nbsp;";
    echo "<a href='javascript:popup(\"planning/poste/supprimer.php\",500,200);'><img src='img/drop-20.gif' alt='Supprimer'/></a>&nbsp;";
  }
}
if($verrou){
  if(!$autorisation){
    echo "<u>Validation</u> $perso2 $date_validation2 $heure_validation2<br/>\n";
  }
  echo "<img id='imprimante' src='img/imprimante.gif' alt='imprimer' onclick='print();'/>\n";
  echo "<script type='text/JavaScript'>refresh_poste('$validation2');</script>";
}

echo "<a href='index.php'><img id='rafraichir' src='img/rafraichir.jpg' alt='rafraichir' /></a>\n";
echo "</div>\n";

echo "</td></tr>\n";

//----------------------	FIN Verrouillage du planning		-----------------------//
echo "</table></div>\n";

//		---------------		FIN Affichage du titre et du calendrier		--------------------------//
//		---------------		Choix du tableau	-----------------------------//	
//	$site : pour affichage de 2 tableaux différents : BMI
$db=new db();
$db->query("SELECT `tableau` FROM `{$dbprefix}pl_poste_tab_affect` WHERE `date`='$date' AND `site`='$site';");
if(!$db->result[0]['tableau'] and !isset($_GET['tableau']) and !isset($_GET['groupe']) and $autorisation){
  $db=new db();
  $db->query("SELECT * FROM `{$dbprefix}pl_poste_tab` order by `nom` DESC;");
  if($db->result){
    echo <<<EOD
    <div id='choix_tableaux'>
    <b>Choisissez un tableau pour le $dateAlpha</b><br/>
    <form name='form' action='index.php' method='get'>
    <input type='hidden' name='page' value='planning/poste/index.php' />
    <input type='hidden' name='site' value='$site' />
    <table>
    <tr><td>Choix d'un tableau : </td>
      <td>
      <select name='tableau'>
      <option value=''>&nbsp;</option>
EOD;
      foreach($db->result as $elem)
	echo "<option value='{$elem['tableau']}'>{$elem['nom']}</option>\n";
      echo <<<EOD
      </select></td>
      <td><input type='submit' value='Valider' /></td></tr>
    </table>
    </form>
EOD;
    if($pasDeDonneesSemaine and $groupes){
      echo <<<EOD
      <br/><br/><b>OU un groupe de tableaux pour la semaine $semaine</b><br/>
      <form name='form' action='index.php' method='get'>
      <input type='hidden' name='page' value='planning/poste/index.php' />
      <input type='hidden' name='site' value='$site' />
      <table>
      <tr><td>Choix d'un groupe : </td>
	<td><select name='groupe'>
	<option value=''>&nbsp;</option>
EOD;
	foreach($groupes as $elem)
	  echo "<option value='{$elem['id']}'>{$elem['nom']}</option>\n";
	echo <<<EOD
	</select></td>
	<td><input type='submit' value='Valider' /></td></tr>
      </table>
      </form>
EOD;
    }
  }
  echo "</div>\n";
  include "include/footer.php";
  exit;
}
elseif(isset($_GET['groupe']) and $autorisation){	//	Si Groupe en argument
  $t=new tableau();
  $t->fetchGroup($_GET['groupe']);
  $groupe=$t->elements;
  $tmp=array();
  $tmp[$dates[0]]=array($dates[0],$groupe['Lundi']);
  $tmp[$dates[1]]=array($dates[1],$groupe['Mardi']);
  $tmp[$dates[2]]=array($dates[2],$groupe['Mercredi']);
  $tmp[$dates[3]]=array($dates[3],$groupe['Jeudi']);
  $tmp[$dates[4]]=array($dates[4],$groupe['Vendredi']);
  $tmp[$dates[5]]=array($dates[5],$groupe['Samedi']);
  if(array_key_exists("Dimanche",$groupe)){
    $tmp[$dates[6]]=array($dates[6],$groupe['Dimanche']);
  }
  foreach($tmp as $elem){
    $db=new db();
    $db->delete("pl_poste_tab_affect","date='{$elem[0]}' AND `site`='$site'");
    $db=new db();
    $db->insert2("pl_poste_tab_affect",array("date"=>$elem[0], "tableau"=>$elem[1], "site"=>$site));
  }
  $tab=$tmp[$date][1];

}
elseif(isset($_GET['tableau']) and $autorisation){	//	Si tableau en argument
  $tab=$_GET['tableau'];
  $db=new db();
  $db->delete("pl_poste_tab_affect","`date`='$date' AND `site`='$site'");
  $db=new db();
  $db->insert2("pl_poste_tab_affect",array("date"=>$date, "tableau"=>$tab, "site"=>$site));
}
else{
  $tab=$db->result[0]['tableau'];
}
if(!$tab){
  echo "Le planning n'est pas validé.\n";
  include "include/footer.php";
  exit;
}

//-------------------------------	FIN Choix du tableau	-----------------------------//	
//-------------------------------	Vérification si le planning semaine fixe est validé	------------------//

if(!$verrou and !$autorisation){
  echo "<br/><br/><font color='red'>Le planning du $dateFr n'est pas validé !</font><br/>\n";
  echo "</body></html>\n";
}
else{
  echo ($verrou ? "<script type='text/JavaScript'>menudiv_display='none';</script>" : "<script type='text/JavaScript'>menudiv_display='';</script>");
  
  echo "<div id='menudiv' style='display:none;' onmouseover='javascript:overpopupmenu=true;' onmouseout='javascript:overpopupmenu=false;'>";
  echo "</div>\n";
  
  //--------------	Recherche des infos cellules	------------//
  // Toutes les infos seront stockées danx un tableau et utilisées par les fonctions cellules_postes
  $db=new db();
  $db->query("SELECT `{$dbprefix}pl_poste`.`perso_id` AS `perso_id`,`{$dbprefix}pl_poste`.`debut` AS `debut`,
  `{$dbprefix}pl_poste`.`fin` AS `fin`, `{$dbprefix}pl_poste`.`poste` AS `poste`, 
  `{$dbprefix}pl_poste`.`absent` AS `absent`, `{$dbprefix}pl_poste`.`supprime` AS `supprime`, 
  `{$dbprefix}pl_poste`.`poste` AS `poste`, `{$dbprefix}personnel`.`nom` AS `nom`, 
  `{$dbprefix}personnel`.`prenom` AS `prenom`, `{$dbprefix}personnel`.`statut` AS `statut`, 
  `{$dbprefix}personnel`.`service` AS `service` 
  FROM `{$dbprefix}pl_poste` 
  INNER JOIN `{$dbprefix}personnel` ON `{$dbprefix}pl_poste`.`perso_id`=`{$dbprefix}personnel`.`id` 
  WHERE `date`='$date' AND `{$dbprefix}pl_poste`.`site`='$site' 
  ORDER BY `{$dbprefix}pl_poste`.`absent` desc,`{$dbprefix}personnel`.`nom`, `{$dbprefix}personnel`.`prenom` ;");
  global $cellules;
  $cellules=$db->result;
  
  //--------------	FIN Recherche des infos cellules	------------//
  
  
  //	------------		Affichage du tableau			--------------------//
  $numero=$tab;
  //	Liste des horaires
  $db=new db();
  $db->query("SELECT * FROM `{$dbprefix}pl_poste_horaires` WHERE `numero` ='$numero' ORDER BY `tableau`,`debut`,`fin`;");
  $horaires=$db->result;

  //	Liste des lignes enregistrées
  $db=new db();
  $db->query("SELECT * FROM `{$dbprefix}pl_poste_lignes` WHERE `numero`='$numero' ORDER BY `tableau`,`ligne`;");
  $lignes=$db->result;

  //	Lignes de separation
  $db=new db();
  $db->select("lignes");
  if($db->result){
    foreach($db->result as $elem){
      $lignes_sep[$elem['id']]=$elem['nom'];
    }
  }

  if(is_array($lignes)){
    foreach($lignes as $elem){
      if($elem['type']=='titre'){
	$index=$elem['tableau'];
	$titres[$index]=$elem['poste'];
      }
    }
  }
	  
  //		Tableau $tab [nom,horaire1[debut,fin],horaire2[debut,fin],horaire3[debut,fin] ... ]
  //		Tri des horaires : général puis réserve puis rangement	
  $tabs=array(array("general"),array("reserve"),array("rangement"));
  if(is_array($horaires)){
    foreach($horaires as $elem){
      if($elem['tableau']=="general")
	$tabs[0][]=$elem;
      if($elem['tableau']=="reserve")
	$tabs[1][]=$elem;
      if($elem['tableau']=="rangement")
	$tabs[2][]=$elem;
    }
  }

  //	Liste des cellules grises
  $db=new db();
  $db->query("SELECT * FROM `{$dbprefix}pl_poste_cellules` WHERE `numero`='$numero' ORDER BY `tableau`,`ligne`,`colonne`;");
  $cellules_grises=array();
  if($db->result)
  foreach($db->result as $elem){
    $cellules_grises[]="{$elem['tableau']}_{$elem['ligne']}_{$elem['colonne']}";
  }

  // affichage du tableau :
  // affichage de la lignes des horaires
  echo "<div id='tableau'>\n";
  echo "<table id='tabsemaine1' cellspacing='0' cellpadding='0' class='text'>\n";
  $k=0;
  foreach($tabs as $tab){
    if(array_key_exists(1,$tab)){
      //		Lignes horaires
      echo "<tr id='tr_horaires'>\n";
      echo "<td class='td_postes'>{$titres[$tab[0]]}</td>\n";
      $colspan=0;
      for($i=1;$i<count($tab);$i++){
	echo "<td colspan='".nb30($tab[$i]['debut'],$tab[$i]['fin'])."'>".heure3($tab[$i]['debut'])."-".heure3($tab[$i]['fin'])."</td>";
	$colspan+=nb30($tab[$i]['debut'],$tab[$i]['fin']);
      }
      echo "</tr>\n";
      
      //	Lignes postes et grandes lignes
      if(is_array($lignes)){
	foreach($lignes as $ligne){
	  if($ligne['tableau']==$tab[0] and $ligne['type']=="poste"){
	    $class=$postes[$ligne['poste']]['obligatoire']=="Obligatoire"?"td_obligatoire":"td_renfort";
	    echo "<tr><td class='$class'>{$postes[$ligne['poste']]['nom']}";
	    if($config['affiche_etage']){
	      echo " ({$postes[$ligne['poste']]['etage']})";
	    }
	    echo "</td>\n";
	    for($i=1;$i<count($tab);$i++){
		    // recherche des infos à afficher dans chaque cellule 
		    // fonction cellule_poste(debut,fin,colspan,affichage,poste)
	      if(in_array("{$tab[0]}_{$ligne['ligne']}_{$i}",$cellules_grises)){
		echo "<td colspan='".nb30($tab[$i]['debut'],$tab[$i]['fin'])."' class='cellule_grise' oncontextmenu='cellule=\"\";' >&nbsp;</td>";
	      }
	      else{
		echo cellule_poste($tab[$i]["debut"],$tab[$i]["fin"],nb30($tab[$i]['debut'],$tab[$i]['fin']),"noms",$ligne['poste']);
	      }
	    }
	    echo "</tr>\n";
	  }
	  if($ligne['tableau']==$tab[0] and $ligne['type']=="ligne"){
	    echo "<tr class='tr_separation'>\n";
	    echo "<td>{$lignes_sep[$ligne['poste']]}</td><td colspan='$colspan'>&nbsp;</td></tr>\n";
	  }
	}
      }
    $k++;
    }
  }
  echo "</table>\n";
}

// Affichage des absences
if($config['absences_planning']){
  $a=new absences();
  $a->fetch("`nom`,`prenom`,`debut`,`fin`",null,null,$date." 00:00:00",$date." 23:59:59");
  switch($config['absences_planning']){
    case "simple" :
      if(!empty($a->elements)){
	echo "<h3 style='text-align:left;margin:40px 0 0 0;'>Liste des absents</h3>\n";
	echo "<table id='planning_absences' cellspacing='0' style='margin:5px 0 0 0;'>\n";
	$class="tr1";
	foreach($a->elements as $elem){
	  $heures=null;
	  $debut=null;
	  $fin=null;
	  if($elem['debut']>"$date 00:00:00"){
	    $debut=substr($elem['debut'],-8);
	  }
	  if($elem['fin']<"$date 23:59:59"){
	    $fin=substr($elem['fin'],-8);
	  }
	  if($debut and $fin){
	    $heures="de ".heure2($debut)." à ".heure2($fin);
	  }
	  elseif($debut){
	    $heures="à partir de ".heure2($debut);
	  }
	  elseif($fin){
	    $heures="jusqu'à ".heure2($fin);
	  }

	  $class=$class=="tr1"?"tr2":"tr1";
	  echo "<tr class='$class'><td>{$elem['nom']} {$elem['prenom']} ({$elem['motif']}) $heures</td></tr>\n";
	}
	echo "</table>\n";
      }
      break;

    case "détaillé" :
      if(!empty($a->elements)){
	echo "<h3 style='text-align:left;margin:40px 0 0 0;'>Liste des absents</h3>\n";
	echo "<table id='planning_absences' cellspacing='0' style='margin:5px 0 0 0;'>\n";
	echo "<tr class='th'><td>Nom</td><td>Pr&eacute;nom</td><td>D&eacute;but</td><td>Fin</td><td>Motif</td></tr>\n";
	$class="tr1";
	foreach($a->elements as $elem){
	  $class=$class=="tr1"?"tr2":"tr1";
	  echo "<tr class='$class'><td>{$elem['nom']}</td><td>{$elem['prenom']}</td>";
	  echo "<td>{$elem['debutAff']}</td><td>{$elem['finAff']}</td>";
	  echo "<td>{$elem['motif']}</td></tr>\n";
	}
	echo "</table>\n";
      }
      break;

    case "absents et présents" :
      // Sélection des agents présents
      $heures=null;
      $presents=array();
      $absents=array(2);	// 2 = Utilisateur "Tout le monde", on le supprime

      // On exclus ceux qui sont absents toute la journée
      $a2=new absences();
      $a2->fetch("`nom`,`prenom`,`debut`,`fin`",null,null,$date." 00:00:00",$date." 23:59:59");
      if($a2->elements){
	foreach($a2->elements as $elem){
	  if($elem['debut']<=$date." 00:00:00" and $elem['fin']>=$date." 23:59:59"){
	    $absents[]=$elem['perso_id'];
	  }
	}
      }
      // recherche des personnes à exclure (ne travaillant ce jour)
      $db=new db();
      $db->query("SELECT * FROM `{$dbprefix}personnel` WHERE `actif` LIKE 'Actif' AND (`depart` > $date OR `depart` = '0000-00-00') ORDER BY `nom`,`prenom`;");

      $verif=true;	// verification des heures des agents
      if(!$config['ctrlHresAgents'] and ($d->position==6 or $d->position==0)){
	$verif=false; // on ne verifie pas les heures des agents le samedi et le dimanche (Si ctrlHresAgents est desactivé)
      }
	      
      if($db->result and $verif){
	foreach($db->result as $elem){
	  $heures=null;
	  $temps=unserialize($elem['temps']);

	  $jour=$d->position-1;		// jour de la semaine lundi = 0 ,dimanche = 6
	  if($jour==-1){
	    $jour=6;
	  }

	  // Si semaine paire, position +7 : lundi A = 0 , lundi B = 7 , dimanche B = 13
	  if($config['nb_semaine']=="2" and !($semaine%2)){
	    $jour+=7;
	  }
	  // Si utilisation de 3 plannings hebdo
	  elseif($config['nb_semaine']=="3"){
	    if($semaine3==2){
	      $jour+=7;
	    }
	    elseif($semaine3==3){
	      $jour+=14;
	    }
	  }

	  // Si l'emploi du temps est renseigné
	  if(!empty($temps) and array_key_exists($jour,$temps)){
	    // S'il y a une heure de début (matin ou midi)
	    if($temps[$jour][0] or $temps[$jour][2]){
	      $heures=$temps[$jour];
	    }
	  }

	  // S'il y a des horaires correctement renseignés
	  if($heures and !in_array($elem['id'],$absents)){
	    $site=null;
	      if($config['Multisites-nombre']>1){
	      if($config['Multisites-agentsMultisites']==1 and isset($heures[4])){
		$site=$config['Multisites-site'.$heures[4]];
	      }
	      else{
		$site=$config['Multisites-site'.$elem['site']];
	      }
	    }
	    $horaires=null;
	    if(!$heures[1] and !$heures[2]){		// Pas de pause le midi
	      $horaires=", ".heure2($heures[0])." - ".heure2($heures[3]);
	    }
	    elseif(!$heures[2] and !$heures[3]){	// matin seulement
	      $horaires=", ".heure2($heures[0])." - ".heure2($heures[1]);
	    }
	    elseif(!$heures[0] and !$heures[1]){	// après midi seulement
	      $horaires=", ".heure2($heures[2])." - ".heure2($heures[3]);
	    }
	    else{		// matin et après midi avec pause
	      $horaires=", ".heure2($heures[0])." - ".heure2($heures[1])." &amp; ".heure2($heures[2])." - ".heure2($heures[3]);
	    }
	    $presents[]=array("id"=>$elem['id'],"nom"=>$elem['nom']." ".$elem['prenom'],"site"=>$site,"heures"=>$horaires);
	  }
	}
      }

      echo "<table id='planning_absences' cellspacing='0' style='margin:5px 0 0 0;' >\n";
      echo "<tr><td style='width:60%;'><h3 style='text-align:left;margin:40px 0 0 0;'>Liste des présents</h3></td>\n";
      if(!empty($a->elements)){
	echo "<td><h3 style='text-align:left;margin:40px 0 0 0;'>Liste des absents</h3></td>";
      }
      echo "</tr>\n";

      // Liste des présents
      echo "<tr style='vertical-align:top;'><td>";
      echo "<table cellspacing='0'> ";
      $class="tr1";
      foreach($presents as $elem){
	$class=$class=="tr1"?"tr2":"tr1";
	echo "<tr class='$class'><td>{$elem['nom']}</td><td style='padding-left:15px;'>{$elem['site']}{$elem['heures']}</td></tr>\n";
      }
      echo "</table>\n";
      echo "</td>\n";

      // Liste des absents
      echo "<td>";
      echo "<table cellspacing='0'>";
      $class="tr1";
      foreach($a->elements as $elem){
	$heures=null;
	$debut=null;
	$fin=null;
	if($elem['debut']>"$date 00:00:00"){
	  $debut=substr($elem['debut'],-8);
	}
	if($elem['fin']<"$date 23:59:59"){
	  $fin=substr($elem['fin'],-8);
	}
	if($debut and $fin){
	  $heures=", ".heure2($debut)." - ".heure2($fin);
	}
	elseif($debut){
	  $heures=" à partir de ".heure2($debut);
	}
	elseif($fin){
	  $heures=" jusqu'à ".heure2($fin);
	}

	$class=$class=="tr1"?"tr2":"tr1";
	echo "<tr class='$class'><td>{$elem['nom']} {$elem['prenom']}</td><td style='padding-left:15px;'>{$elem['motif']}{$heures}</td></tr>\n";
      }
      echo "</table>\n";
      echo "</td></tr>\n";
      echo "</table>\n";
      break;

  }
}
					//---------------	FIN Affichage des absences		-----------------//
?>
</div>
</div>
<script type='text/JavaScript'>		//--------------	Modification du menu contextuel		----------------//
<?php
echo "date='$date';";
?>
document.onmousedown  = mouseSelect;
document.getElementById('tableau').oncontextmenu  = ItemSelMenu;
					//--------------	FIN Modification du menu contextuel	----------------//
</script>