<?php
/**
Planning Biblio, Version 2.7.04
Licence GNU/GPL (version 2 et au dela)
Voir les fichiers README.md et LICENSE
@copyright 2011-2018 Jérôme Combes

Fichier : statistiques/agents.php
Création : mai 2011
Dernière modification : 22 novembre 2017
@author Jérôme Combes <jerome@planningbiblio.fr>

Description :
Affiche les statistiques par agent: nom des postes occupés, nombre d'heures par poste, nombre de samedis travaillés,
jours feriés travaillés, nombre d'heures d'absences

Page appelée par le fichier index.php, accessible par le menu statistiques / Par agents
*/

require_once "class.statistiques.php";
require_once "include/horaires.php";
require_once "absences/class.absences.php";

// Initialisation des variables :
$debut=filter_input(INPUT_POST, "debut", FILTER_SANITIZE_STRING);
$fin=filter_input(INPUT_POST, "fin", FILTER_SANITIZE_STRING);
$statistiques_heures = filter_input(INPUT_POST, "statistiques_heures", FILTER_SANITIZE_STRING);
$statistiques_heures_defaut = filter_input(INPUT_POST, "statistiques_heures_defaut", FILTER_SANITIZE_NUMBER_INT);
$post=filter_input_array(INPUT_POST, FILTER_SANITIZE_NUMBER_INT);

$debut=filter_var($debut, FILTER_CALLBACK, array("options"=>"sanitize_dateFr"));
$fin=filter_var($fin, FILTER_CALLBACK, array("options"=>"sanitize_dateFr"));

$post_agents=isset($post['agents'])?$post['agents']:null;
$post_sites=isset($post['selectedSites'])?$post['selectedSites']:null;

$joursParSemaine=$config['Dimanche']?7:6;
$agent_tab=null;
$exists_JF=false;
$exists_absences=false;
$exists_samedi=false;
$exists_dimanche=false;

// Statistiques-Heures
$heures_tab_global = array();
if ($statistiques_heures_defaut) {
    $statistiques_heures = $config['Statistiques-Heures'];
} else {
    if (!$statistiques_heures and !empty($_SESSION['oups']['statistiques_heures'])) {
        $statistiques_heures = $_SESSION['oups']['statistiques_heures'];
    } elseif (!$statistiques_heures and !empty($config['Statistiques-Heures'])) {
        $statistiques_heures = $config['Statistiques-Heures'];
    }
}

$_SESSION['oups']['statistiques_heures'] = $statistiques_heures;

if (!$debut and array_key_exists('stat_debut', $_SESSION)) {
    $debut=$_SESSION['stat_debut'];
}
if (!$fin and array_key_exists('stat_fin', $_SESSION)) {
    $fin=$_SESSION['stat_fin'];
}

if (!$debut) {
    $debut="01/01/".date("Y");
}
if (!$fin) {
    $fin=date("d/m/Y");
}

$_SESSION['stat_debut']=$debut;
$_SESSION['stat_fin']=$fin;

$debutSQL=dateFr($debut);
$finSQL=dateFr($fin);

// Filtre les agents
if (!array_key_exists('stat_agents_agents', $_SESSION)) {
    $_SESSION['stat_agents_agents']=null;
}

$agents=array();
if ($post_agents) {
    foreach ($post_agents as $elem) {
        $agents[]=$elem;
    }
} else {
    $agents=$_SESSION['stat_agents_agents'];
}
$_SESSION['stat_agents_agents']=$agents;


// Filtre les sites
if (!array_key_exists('stat_agents_sites', $_SESSION)) {
    $_SESSION['stat_agents_sites']=array();
}

$selectedSites=array();
if ($post_sites) {
    foreach ($post_sites as $elem) {
        $selectedSites[]=$elem;
    }
} else {
    $selectedSites=$_SESSION['stat_agents_sites'];
}

if ($config['Multisites-nombre']>1 and empty($selectedSites)) {
    for ($i=1;$i<=$config['Multisites-nombre'];$i++) {
        $selectedSites[]=$i;
    }
}
$_SESSION['stat_agents_sites']=$selectedSites;

// Filtre les sites dans les requêtes SQL
if ($config['Multisites-nombre']>1 and is_array($selectedSites)) {
    $sitesSQL="0,".join(",", $selectedSites);
} else {
    $sitesSQL="0,1";
}

$tab=array();
//		--------------		Récupération de la liste des agents pour le menu déroulant		------------------------
$db=new db();
$db->select2("personnel", "*", array("actif"=>"Actif"), "ORDER BY `nom`,`prenom`");
$agents_list=$db->result;

if (!empty($agents)) {

    // Recherche des absences dans la table absences
    $a=new absences();
    $a->valide=true;
    $a->fetchForStatistics("$debutSQL 00:00:00", "$finSQL 23:59:59");
    $absencesDB=$a->elements;

    //	Recherche du nombre de jours concernés
    $db=new db();
    $debutREQ=$db->escapeString($debutSQL);
    $finREQ=$db->escapeString($finSQL);
    $sitesREQ=$db->escapeString($sitesSQL);
    $db->select("pl_poste", "`date`", "`date` BETWEEN '$debutREQ' AND '$finREQ' AND `site` IN ($sitesREQ)", "GROUP BY `date`;");
    $nbJours=$db->nb;

    //	Recherche des infos dans pl_poste et postes pour tous les agents sélectionnés
    //	On stock le tout dans le tableau $resultat
    $agents_select=join($agents, ",");
    $db=new db();
    $debutREQ=$db->escapeString($debutSQL);
    $finREQ=$db->escapeString($finSQL);
    $sitesREQ=$db->escapeString($sitesSQL);
    $agentsREQ=$db->escapeString($agents_select);

    $req="SELECT `{$dbprefix}pl_poste`.`debut` as `debut`, `{$dbprefix}pl_poste`.`fin` as `fin`, 
    `{$dbprefix}pl_poste`.`date` as `date`, `{$dbprefix}pl_poste`.`perso_id` as `perso_id`, 
    `{$dbprefix}pl_poste`.`poste` as `poste`, `{$dbprefix}pl_poste`.`absent` as `absent`, 
    `{$dbprefix}postes`.`nom` as `poste_nom`, `{$dbprefix}postes`.`etage` as `etage`, 
    `{$dbprefix}pl_poste`.`site` as `site` 
    FROM `{$dbprefix}pl_poste` 
    INNER JOIN `{$dbprefix}postes` ON `{$dbprefix}pl_poste`.`poste`=`{$dbprefix}postes`.`id` 
    WHERE `{$dbprefix}pl_poste`.`date`>='$debutREQ' AND `{$dbprefix}pl_poste`.`date`<='$finREQ' 
    AND `{$dbprefix}pl_poste`.`supprime`<>'1' AND `{$dbprefix}postes`.`statistiques`='1' 
    AND `{$dbprefix}pl_poste`.`perso_id` IN ($agentsREQ) AND `{$dbprefix}pl_poste`.`site` IN ($sitesREQ) 
    ORDER BY `poste_nom`,`etage`;";
    $db->query($req);
    $resultat=$db->result;
  
    //	Recherche des infos dans le tableau $resultat (issu de pl_poste et postes)
    //	pour chaques agents sélectionnés
    foreach ($agents as $agent) {
        if (array_key_exists($agent, $tab)) {
            $heures=$tab[$agent][2];
            $total_absences=$tab[$agent][5];
            $samedi=$tab[$agent][3];
            $dimanche=$tab[$agent][6];
            $heures_tab = $tab[$agent][7];
            $absences=$tab[$agent][4];
            $feries=$tab[$agent][8];
            $sites=$tab[$agent]["sites"];
        } else {
            $heures=0;
            $total_absences=0;
            $samedi=array();
            $dimanche=array();
            $absences=array();
            $heures_tab = array();
            $feries=array();
            $sites=array();
            for ($i=1;$i<=$config['Multisites-nombre'];$i++) {
                $sites[$i]=0;
            }
        }
        $postes=array();
        if (is_array($resultat)) {
            foreach ($resultat as $elem) {
                if ($agent==$elem['perso_id']) {

                    // Vérifie à partir de la table absences si l'agent est absent
                    // S'il est absent, on met à 1 la variable $elem['absent']
                    if ( !empty($absencesDB[$elem['perso_id']]) ) {
                        foreach ($absencesDB[$elem['perso_id']] as $a) {
                            if ($a['debut']< $elem['date'].' '.$elem['fin'] and $a['fin']> $elem['date']." ".$elem['debut']) {
                                $elem['absent']="1";
                            }
                        }
                    }

                    if ($elem['absent']!="1") {		// on compte les heures et les samedis pour lesquels l'agent n'est pas absent
                        // on créé un tableau par poste avec son nom, étage et la somme des heures faites par agent
                        if (!array_key_exists($elem['poste'], $postes)) {
                            $postes[$elem['poste']]=array($elem['poste'],$elem['poste_nom'],$elem['etage'],0,"site"=>$elem['site']);
                        }
                        // On compte toutes les heures pour ce poste (index 3)
                        $postes[$elem['poste']][3]+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                        // On compte les heures de chaque site
                        if ($config['Multisites-nombre']>1) {
                            $sites[$elem['site']]+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                        }
                        // On compte toutes les heures (globales)
                        $heures+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                        $d=new datePl($elem['date']);
                        if ($d->sam=="samedi") {	// tableau des samedis
                            if (!array_key_exists($elem['date'], $samedi)) { // on stock les dates et la somme des heures faites par date
                            $samedi[$elem['date']][0]=$elem['date'];
                                $samedi[$elem['date']][1]=0;
                            }
                            $samedi[$elem['date']][1]+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                            $exists_samedi=true;
                        }
                        if ($d->position==0) {		// tableau des dimanches
                            if (!array_key_exists($elem['date'], $dimanche)) { 	// on stock les dates et la somme des heures faites par date
                            $dimanche[$elem['date']][0]=$elem['date'];
                                $dimanche[$elem['date']][1]=0;
                            }
                            $dimanche[$elem['date']][1]+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                            $exists_dimanche=true;
                        }
                        if (jour_ferie($elem['date'])) {
                            if (!array_key_exists($elem['date'], $feries)) {
                                $feries[$elem['date']][0]=$elem['date'];
                                $feries[$elem['date']][1]=0;
                            }
                            $feries[$elem['date']][1]+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                            $exists_JF=true;
                        }

                        // Statistiques-Heures
                        if ($statistiques_heures) {
                            $statistiques_heures_tab = explode(';', $statistiques_heures);
                            foreach ($statistiques_heures_tab as $h) {
                                $tmp = heures($h);
                                if (!$tmp) {
                                    continue;
                                }
                
                                if ($elem['debut'] == $tmp[0] and $elem['fin'] == $tmp[1]) {
                                    $heures_tab[$tmp[0].'-'.$tmp[1]][] = $elem['date'];
                                    if (!in_array($tmp, $heures_tab_global)) {
                                        $heures_tab_global[] = $tmp;
                                    }
                                }
                            }
                        }
                    } else {				// On compte les absences
                        if (!array_key_exists($elem['date'], $absences)) {
                            $absences[$elem['date']][0]=$elem['date'];
                            $absences[$elem['date']][1]=0;
                        }
                        $absences[$elem['date']][1]+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                        $total_absences+=diff_heures($elem['debut'], $elem['fin'], "decimal");
                        $exists_absences=true;
                    }

                    foreach ($agents_list as $elem2) {
                        if ($elem2['id']==$agent) {	// on créé un tableau avec le nom et le prénom de l'agent.
                            $agent_tab=array($agent,$elem2['nom'],$elem2['prenom']);
                            break;
                        }
                    }

                    // On met dans tab tous les éléments (infos postes + agents + heures)
                    $tab[$agent]=array($agent_tab,$postes,$heures,$samedi,$absences,$total_absences,$dimanche,$heures_tab,$feries,"sites"=>$sites);
                }
            }
        }
    }
}

// Heures et jours d'ouverture au public
$s=new statistiques();
$s->debut=$debutSQL;
$s->fin=$finSQL;
$s->joursParSemaine=$joursParSemaine;
$s->selectedSites=$selectedSites;
$s->ouverture();
$ouverture=$s->ouvertureTexte;

sort($heures_tab_global);

// passage en session du tableau pour le fichier export.php

$_SESSION['stat_tab']=$tab;

//		--------------		Affichage en 2 partie : formulaire à gauche, résultat à droite
echo "<h3>Statistiques par agent</h3>\n";
echo "<table><tr style='vertical-align:top;'><td id='stat-col1'>\n";
//		--------------		Affichage du formulaire permettant de sélectionner les dates et les agents		-------------
echo "<form name='form' id='form' action='index.php' method='post'>\n";
echo "<input type='hidden' name='page' value='statistiques/agents.php' />\n";
echo "<input type='hidden' name='statistiques_heures_defaut' id='statistiques_heures_defaut_hidden' value='0' />\n";
echo "<table>\n";
echo "<tr><td><label class='intitule'>Début</label></td>\n";
echo "<td><input type='text' name='debut' value='$debut' class='datepicker'/>\n";
echo "</td></tr>\n";
echo "<tr><td><label class='intitule'>Fin</label></td>\n";
echo "<td><input type='text' name='fin' value='$fin' class='datepicker'/>\n";
echo "</td></tr>\n";
echo "<tr style='vertical-align:top'><td><label class='intitule'>Agents</label></td>\n";
echo "<td><select name='agents[]' multiple='multiple' size='20' onchange='verif_select(\"agents\");' class='ui-widget-content ui-corner-all' >\n";

if (is_array($agents_list)) {
    echo "<option value='Tous'>Tous</option>\n";
    foreach ($agents_list as $elem) {
        $selected = null;
        if ($agents) {
            $selected=in_array($elem['id'], $agents)?"selected='selected'":null;
        }
        echo "<option value='{$elem['id']}' $selected>{$elem['nom']} {$elem['prenom']}</option>\n";
    }
}
echo "</select></td></tr>\n";

echo "<tr style='vertical-align:top;'><td><label for='statistiques_heures' class='intitule'>Heures<sup>*</sup></label></td>\n";
echo "<td><textarea name='statistiques_heures' rows='7' class='ui-widget-content ui-corner-all'>$statistiques_heures</textarea><br/>\n";
echo "<a href='#' id='statistiques_heures_defaut_lien'>Charger les heures par d&eacute;faut</a></td></tr>\n";

if ($config['Multisites-nombre']>1) {
    $nbSites=$config['Multisites-nombre'];
    echo "<tr style='vertical-align:top'><td><label class='intitule'>Sites</label></td>\n";
    echo "<td><select name='selectedSites[]' multiple='multiple' size='".($nbSites+1)."' onchange='verif_select(\"selectedSites\");' class='ui-widget-content ui-corner-all' >\n";
    echo "<option value='Tous'>Tous</option>\n";
    for ($i=1;$i<=$nbSites;$i++) {
        $selected=in_array($i, $selectedSites)?"selected='selected'":null;
        echo "<option value='$i' $selected>{$config["Multisites-site$i"]}</option>\n";
    }
    echo "</select></td></tr>\n";
}

echo "<tr><td colspan='2' style='text-align:center;padding:10px;'>\n";
echo "<input type='button' value='Effacer' onclick='location.href=\"index.php?page=statistiques/agents.php&amp;debut=&amp;fin=&amp;agents=\"' class='ui-button' />\n";
echo "&nbsp;&nbsp;<input type='submit' value='OK' class='ui-button' />\n";
echo "</td></tr>\n";
echo "<tr><td colspan='2'><hr/></td></tr>\n";
echo "<tr><td>Exporter </td>\n";
echo "<td><a href='javascript:export_stat(\"agent\",\"csv\");'>CSV</a>&nbsp;&nbsp;\n";
echo "<a href='javascript:export_stat(\"agent\",\"xsl\");'>XLS</a></td></tr>\n";
echo "<tr><td colspan='2'><hr/></td></tr>\n";
echo "<tr><td colspan='2'><p><sup>*</sup>Afficher des statistiques sur les cr&eacute;neaux horaires voulus.<br/>\n";
echo "Les créneaux doivent être au format 00h00-00h00 et séparés par des ;<br/>\n";
echo "Exemple : 19h00-20h00; 20h00-21h00; 21h00-22h00</p></td></tr>\n";
echo "</table>\n";
echo "</form>\n";

//		--------------------------		2eme partie (2eme colonne)		--------------------------
echo "</td><td>\n";

// 		--------------------------		Affichage du tableau de résultat		--------------------
if ($tab) {
    echo "<b>Statistiques par agent du $debut au $fin</b>\n";
    echo $ouverture;
    echo "<table border='1' cellspacing='0' cellpadding='0'>\n";
    echo "<tr class='th'>\n";
    echo "<td style='width:200px; padding-left:8px;'>Agents</td>\n";
    echo "<td style='width:280px; padding-left:8px;'>Postes</td>\n";
    if ($exists_samedi) {
        echo "<td style='width:120px; padding-left:8px;'>Samedi</td>\n";
    }
    if ($exists_dimanche) {
        echo "<td style='width:120px; padding-left:8px;'>Dimanche</td>\n";
    }
    if ($exists_JF) {
        echo "<td style='width:120px; padding-left:8px;'>J. Feri&eacute;s</td>\n";
    }
    if ($exists_absences) {
        echo "<td style='width:120px; padding-left:8px;'>Absences</td>\n";
    }
  
    foreach ($heures_tab_global as $v) {
        echo "<td style='width:120px; padding-left:8px;'>".heure3($v[0]).'-'.heure3($v[1])."</td>\n";
    }

    echo "<tr>\n";

    foreach ($tab as $elem) {
        // Calcul des moyennes
        $jour=$elem[2]/$nbJours;
        $hebdo=$jour*$joursParSemaine;

        echo "<tr style='vertical-align:top;'>\n";
        //	Affichage du nom des agents dans la 1ère colonne
        echo "<td style='padding-left:8px;'>\n";
        echo "<table><tr><td colspan='2'><b>{$elem[0][1]} {$elem[0][2]}</b></td></tr>\n";
        echo "<tr><td>Total</td>\n";
        echo "<td class='statistiques-heures'>".heure4($elem[2])."</td></tr>\n";
        echo "<tr><td>Moyenne hebdo</td>\n";
        echo "<td class='statistiques-heures'>".heure4($hebdo)."</td></tr>\n";
        if ($config['Multisites-nombre']>1) {
            for ($i=1;$i<=$config['Multisites-nombre'];$i++) {
                if ($elem["sites"][$i]) {
                    // Calcul des moyennes
                    $jour=$elem["sites"][$i]/$nbJours;
                    $hebdo=$jour*$joursParSemaine;
                    echo "<tr><td colspan='2' style='padding-top:20px;'><u>".$config["Multisites-site{$i}"]."</u></td></tr>";
                    echo "<tr><td>Total</td>";
                    echo "<td class='statistiques-heures'>".heure4($elem["sites"][$i])."</td></tr>";
                    ;
                    echo "<tr><td>Moyenne</td>";
                    echo "<td class='statistiques-heures'>".heure4($hebdo)."</td></tr>";
                }
            }
        }
        echo "</table>\n";
        echo "</td>\n";
        //	Affichage du noms des postes et des heures dans la 2eme colonne
        echo "<td style='padding-left:8px;'>";
        echo "<table>\n";
        foreach ($elem[1] as $poste) {
            $site=null;
            if ($poste["site"]>0 and $config['Multisites-nombre']>1) {
                $site=$config["Multisites-site{$poste['site']}"]." ";
            }
            $etage=$poste[2]?$poste[2]:null;

            $siteEtage=($site or $etage)?"(".trim($site.$etage).")":null;
            echo "<tr style='vertical-align:top;'><td>\n";
            echo "<b>{$poste[1]}</b><br/><i>$siteEtage</i>";
            echo "</td><td>\n";
            echo heure4($poste[3]);
            echo "</td></tr>\n";
        }
        echo "</table>\n";
        echo "</td>\n";
        //	Affichage du nombre de samedis travaillés et les heures faites par samedi
        if ($exists_samedi) {
            echo "<td style='padding-left:8px;'>";
            $samedi=count($elem[3])>1?"samedis":"samedi";
            echo count($elem[3])." $samedi";		//	nombre de samedi
            echo "<br/>\n";
            sort($elem[3]);				//	tri les samedis par dates croissantes
      foreach ($elem[3] as $samedi) {			//	Affiche les dates et heures des samedis
    echo dateFr($samedi[0]);			//	date
    echo "&nbsp;:&nbsp;".heure4($samedi[1])."<br/>";	// heures
      }
            echo "</td>\n";
        }

        if ($exists_dimanche) {
            echo "<td style='padding-left:8px;'>";
            $dimanche=count($elem[6])>1?"dimanches":"dimanche";
            echo count($elem[6])." $dimanche";	//	nombre de dimanche
            echo "<br/>\n";
            sort($elem[6]);				//	tri les dimanches par dates croissantes
      foreach ($elem[6] as $dimanche) {		//	Affiche les dates et heures des dimanches
    echo dateFr($dimanche[0]);		//	date
    echo "&nbsp;:&nbsp;".heure4($dimanche[1])."<br/>";	//	heures
      }
            echo "</td>\n";
        }

        if ($exists_JF) {
            echo "<td style='padding-left:8px;'>";					//	Jours feries
            $ferie=count($elem[8])>1?"J. feri&eacute;s":"J. feri&eacute;";
            echo count($elem[8])." $ferie";		//	nombre de dimanche
            echo "<br/>\n";
            sort($elem[8]);				//	tri les dimanches par dates croissantes
      foreach ($elem[8] as $ferie) {		// 	Affiche les dates et heures des dimanches
    echo dateFr($ferie[0]);			//	date
    echo "&nbsp;:&nbsp;".heure4($ferie[1])."<br/>";	//	heures
      }
            echo "</td>";
        }

        // Absences
        if ($exists_absences) {
            echo "<td>\n";
            if ($elem[5]) {				//	Affichage du total d'heures d'absences
                echo "Total : ".heure4($elem[5])."<br/>";
            }
            sort($elem[4]);				//	tri les absences par dates croissantes
      foreach ($elem[4] as $absences) {		//	Affiche les dates et heures des absences
    echo dateFr($absences[0]);		//	date
    echo "&nbsp;:&nbsp;".heure4($absences[1])."<br/>";	// heures
    // echo "&nbsp;:&nbsp;".$absences[1]."<br/>";	// heures
      }
            echo "</td>\n";
        }

        // Statistiques-Heures
        foreach ($heures_tab_global as $v) {
            $h1 = heure3($v[0]);
            $h2 = heure3($v[1]);
            $v = $v[0].'-'.$v[1];
      
            echo "<td>\n";
            if (!empty($elem[7][$v])) {
                sort($elem[7][$v]);
                echo "Nb $h1-$h2 : ";
                echo count($elem[7][$v]);
                foreach ($elem[7][$v] as $h) {
                    echo "<br/>".dateFr($h);
                }
            }
            echo "</td>\n";
        }

        echo "</tr>\n";
    }
    echo "</table>\n";
}
//		----------------------			Fin d'affichage		----------------------------
echo "</td></tr></table>\n";
