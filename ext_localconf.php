<?php
if (!defined ('TYPO3_MODE')) 	die ('Access denied.');

	require_once (t3lib_extMgm::extPath ('ter_doc').'class.tx_terdoc_renderdocuments.php');
	require_once (t3lib_extMgm::extPath ('ter_doc_html').'class.tx_terdochtml_readonline.php');

	$renderDocsObj = tx_terdoc_renderdocuments::getInstance();
	$renderDocsObj->registerOutputFormat ('ter_doc_html_onlinehtml', 'LLL:EXT:ter_doc_html/locallang.xml:format_readonline', 'display', new tx_terdochtml_readonline);

?>