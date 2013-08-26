<?php
/********************************************************************************************************************************
* Planning Biblio, Version 1.5.2													*
* Licence GNU/GPL (version 2 et au dela)											*
* Voir les fichiers README.txt et COPYING.txt											*
* Copyright (C) 2011-2013 - Jérôme Combes											*
*																*
* Fichier : include/footer.php													*
* Création : mai 2011														*
* Dernière modification : 17 janvier 2013											*
* Auteur : Jérôme Combes, jerome@planningbilbio.fr										*
*																*
* Description :															*
* Affcihe le pied de page													*
* Page notamment appelée par les fichiers index.php et admin/index.php								*
*********************************************************************************************************************************/

// pas de $version=acces direct  => redirection vers la page index.php
if(!$version){
  header("Location: ../index.php");
}
?>
<div class='footer'>
PlanningBiblio (<?php echo $version; ?>) - Copyright &copy; 2011-2013 - Jérôme Combes - 
<a href='http://www.planningbiblio.fr' target='_blank' style='font-size:9pt;'>www.planningbiblio.fr</a>
</div>
</body>
</html>