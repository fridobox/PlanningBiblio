<?php
/********************************************************************************************************************************
* Planning Biblio, Version 1.5.2													*
* Licence GNU/GPL (version 2 et au dela)											*
* Voir les fichiers README.txt et COPYING.txt											*
* Copyright (C) 2011-2013 - Jérôme Combes											*
*																*
* Fichier : include/horaires.php												*
* Création : mai 2011														*
* Dernière modification : 17 janvier 2013											*
* Auteur : Jérôme Combes, jerome@planningbilbio.fr										*
*																*
* Description :															*
* Contient les fonctions permettant de travailler sur les horaires								*
* Mise en forme des heures, soustraction d'horaires, 										*
*********************************************************************************************************************************/

// pas de $version=acces direct  => redirection vers la page index.php
if(!$version){
  header("Location: ../index.php");
}

function diff_heures($debut,$fin,$format){
  $debut=explode(":",$debut);
  $fin=explode(":",$fin);
  $debut=$debut[0]*60+$debut[1];
  $fin=$fin[0]*60+$fin[1];
  $diff=$fin-$debut;
  
  switch($format){
    case "minutes" : return $diff; break;
    case "decimal" : return $diff/60; break;
    case "heures" : return $diff/60; break;		// heures + min *100/60
  }
}
?>