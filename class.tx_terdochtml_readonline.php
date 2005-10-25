<?php

/***************************************************************
*  Copyright notice
*
*  (c) 2005 Robert Lemke (robert@typo3.org)
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * Docbook to HTML rendering class for reading online 
 *
 * $Id$
 *
 * @author	Robert Lemke <robert@typo3.org>
 */

class tx_terdochtml_readonline {

	/**
	 * Renders the cache for online reading of documents. The result consists
	 * of various files - one for each chapter and section - which are stored
	 * in the document cache directory.
	 * 
	 * @param	string		$documentDir: Absolute directory for the document currently being processed.
	 * @return	void		
	 * @access	public
	 */
	public function renderCache ($documentDir) {

			// Prepare output directory:
		if (@is_dir ($documentDir.'html_online')) $this->removeDirRecursively ($documentDir.'html_online');
		@mkdir ($documentDir.'html_online');
	
		$docBookDom = new DomDocument();
		$docBookDom->load($documentDir.'docbook/manual.xml');
		if (!$docBookDom) return FALSE;
		
			// Transform the DocBook manual to XHTML into various files, each containing one chapter:
		$xsl = new DomDocument();
		$xsl->load(t3lib_extMgm::extPath ('ter_doc_html').'docbook-xsl/xhtml/chunk.xsl');
				
		$xsltProc = new XsltProcessor();
		$xsltProc->setParameter ('','base.dir',$documentDir.'html_online/');
		$xsltProc->setParameter ('','section.autolabel','1');
		$xsltProc->setParameter ('','section.label.includes.component.label','1');
		$xsltProc->setParameter ('','section.autolabel.max.depth','1');
		$xsltProc->setParameter ('','generate.toc', 'book nop');
		$xsltProc->setParameter ('','suppress.navigation','1');
		if ($xsltProc->hasExsltSupport()) {
			$xsltProc->setParameter ('','chunk.fast','1');
		}	
		
		$oldErrorLevel = error_reporting();
		error_reporting(E_ERROR);
		$xsl = $xsltProc->importStylesheet($xsl);
		$xsltProc->transformToDoc($docBookDom);
		error_reporting($oldErrorLevel);
	}

	/**
	 * Renders the online view of a document. This function will be called by
	 * the frontend plugin (ter_doc_pi1).
	 * 
	 * @param	string		$extensionKey: Extension key of the document to be rendered
	 * @param	string		$version: Version number of the document to be rendered
	 * @param	object		$pObj: Reference to the calling object (must be a pi_base child). Used for creating links etc. 
	 * @return	string			
	 * @access	public
	 */
	public function renderDisplay ($extensionKey, $version, &$pObj) {
		global $TSFE;
		
		$manualArr = $this->db_fetchManualRecord ($extensionKey, $version);

		$documentDir = $this->getDocumentDirOfExtensionVersion ($extensionKey, $version);		
		$tocArr = unserialize (@file_get_contents ($documentDir.'toc.dat'));
		if (!is_array ($tocArr)) return 'ERROR: Corrupted table of content! (renderDisplay)';
		
		if (!intval($pObj->piVars['html_readonline_chapter'])) $pObj->piVars['html_readonline_chapter'] = 'toc';

		if ($pObj->piVars['html_readonline_chapter'] == 'toc') {			
			$content = $this->renderDisplay_renderTOC ($tocArr, $manualArr, $pObj);
		} else {
			
			$csInfoArr = $this->getChapterSectionInformation ($extensionKey, $version, $pObj);
			extract ($csInfoArr);  // $currentChapter, $currentSection, $previousChapter, $previousSection, $nextChapter, $nextSection
						
			$currentChapterFileName = 'ch'.($currentChapter < 10 ? '0' : '') . $currentChapter . ($currentSection > 1 ? 's'.($currentSection < 10 ? '0' : '') . $currentSection : '').'.html';
	
			$previousLabel = $this->csConvHSC ($previousChapter.'.'.$previousSection.'.' . $tocArr[$previousChapter]['sections'][$previousSection]['title']);
			$nextLabel = $this->csConvHSC ($nextChapter.'.'.$nextSection.'.'. $tocArr[$nextChapter]['sections'][$nextSection]['title']);
			$currentChapterLabel = $this->csConvHSC ($currentChapter.'.'. $tocArr[$currentChapter]['title']);
			
			$previousLink = isset ($previousChapter) ? $pObj->pi_linkTP_keepPIvars($previousLabel, array('html_readonline_chapter' => $previousChapter, 'html_readonline_section' => $previousSection), 1) : '&nbsp';
			$nextLink = isset ($nextChapter) ? $pObj->pi_linkTP_keepPIvars($nextLabel, array('html_readonline_chapter' => $nextChapter, 'html_readonline_section' => $nextSection), 1) : '&nbsp';
			
			$navigationBar = '
				<div class="tx-terdochtml-topbar">
					<table class="tx-terdochtml-navigation">
						<tr>
							<td class="tx-terdochtml-navigation-left">'.$previousLink.'</td>
							<td class="tx-terdochtml-navigation-center">'.$pObj->pi_linkTP_keepPIvars($pObj->pi_getLL('general_tableofcontent','',1), array('html_readonline_chapter' => 'toc', 'html_readonline_section' => '0'), 1).'</td>
							<td class="tx-terdochtml-navigation-right">'.$nextLink.'</td>
						</tr>
					</table>
				</div>
			';
			
			$chapterHTML = $TSFE->csConv (file_get_contents ($documentDir.'html_online/'.$currentChapterFileName), 'utf-8');
			$chapterHTML = $this->renderDisplay_renderImages($chapterHTML, $documentDir, $pObj);

			$content = '
				  <br />
				'.$navigationBar 
				 .$chapterHTML
				 .'<br />'
				 .(strlen ($chapterHTML) > 3500 ? $navigationBar : '')
				 .'<br />';
		}
				
		$output = '
			<div class="tx-terdochtml">
				'.$content.'
			</div>
		';	
		
		return $output;
	}

	/**
	 * Returns TRUE if a rendered document for the given extension version is
	 * available.
	 * 
	 * @param	string		$extensionKey: Extension key of the document
	 * @param	string		$version: Version number of the document
	 * @return	boolean		TRUE if rendered version is available, otherwise FALSE		
	 * @access	public
	 */
	public function isAvailable ($extensionKey, $version) {
		$documentDir = $this->getDocumentDirOfExtensionVersion ($extensionKey, $version);
		return @is_file ($documentDir.'html_online/ch01.html');
	}





	/**
	 * Renders the table of content from the given TOC array
	 *
	 * @param	array		$tocArr: Array containing the table of content
	 * @param	array		$manualArr: Database record of the current manual
	 * @param	object		$pObj: Reference to the plugin object
	 * @return	string		HTML output - the table of content 
	 * @access	protected
	 */
	protected function renderDisplay_renderTOC ($tocArr, $manualArr, &$pObj) {

		$output = '';
				
		if (is_array ($tocArr) && is_array($manualArr)) {
			$title = $this->csConvHSC($manualArr['title']);
			$author =  $pObj->cObj->getTypoLink ($this->csConvHSC($manualArr['authorname']), $manualArr['authoremail']);
			$email =  $pObj->cObj->getTypoLink (implode ('@<span style="display:none;">no spam please</span>', explode ('@', $manualArr['authoremail'])), $manualArr['authoremail']);	
			$versionInfo = '<p>This document is related to version '.$manualArr['version'].' of the extension '.$this->csConvHSC($manualArr['extensionkey']).'.</p>';

			$output .= '
				<h2>'.$title.'</h2>
				Copyright &copy; by '.$author.' &lt;'.$email.'&gt;<br />
				Published under the Open Content License available from <a href="http://www.opencontent.org/opl.shtml" target="_new">http://www.opencontent.org/opl.shtml</a><br />
				<br />
				<h3>Table Of Contents</h3>
				<br />
				<ul>
			';
			foreach ($tocArr as $chapterNr => $chapterArr) {
				$output .= '<li class="level-1">'.$chapterNr.'. ';
				$output .= $pObj->pi_linkTP_keepPIvars($this->csConvHSC($chapterArr['title']), array('html_readonline_chapter' => $chapterNr, 'html_readonline_section' => 1), 1);
				if (is_array ($chapterArr['sections'])) {
					$output .= '<ul>';
					foreach ($chapterArr['sections'] as $sectionNr => $sectionArr) {
						$output .= '<li class="level-2">'.$chapterNr.'.'.$sectionNr.'. ';
						$output .= $pObj->pi_linkTP_keepPIvars($this->csConvHSC($sectionArr['title']), array('html_readonline_chapter' => $chapterNr, 'html_readonline_section' => $sectionNr), 1);
						if (is_array ($sectionArr['subsections'])) {
							$output .= '<ul>';
							foreach ($sectionArr['subsections'] as $subSectionNr => $subSectionArr) {
								$output .= '<li class="level-3">';
								$output .= $pObj->pi_linkTP_keepPIvars($this->csConvHSC($subSectionArr['title']), array('html_readonline_chapter' => $chapterNr, 'html_readonline_section' => $sectionNr), 1);
								$output .= '</li>';
							}	
							$output .= '</ul>';
						}
						$output .= '</li>';
					}	
					$output .= '</ul>';
				}
				$linesArr[] = '</li>';
			}
			$output .= '
				</ul>
				<br />
				'.$versionInfo.'
			';
		}
		return $output;
	}

	/**
	 * Renders all images which appear in the given HTML code so they don't exceed
	 * a certain maximum width and come with a click-enlarge link if they would.
	 * 
	 * Note: Only images which are found in the docbook/pictures/ directory will be
	 *       processed. 
	 *
	 * @param	string		$html: HTML code to browse for img tags
	 * @param	string		$documentDir: The document directory of the currently processed extension version
	 * @param	object		$pObj: Reference of the plugin object
	 * @return	string		HTML output - with replaced images. 
	 * @access	protected
	 */
	protected function renderDisplay_renderImages($html, $documentDir, &$pObj) {

		$gifBuilderObj = t3lib_div::makeInstance('tslib_gifbuilder');
		$gifBuilderObj->init();

		$linkWrapConf = array (
			'enable' => '1',
			'title' => '',
			'bodyTag' => '<body style="margin: 0; background: #FFFFFF;">',
			'wrap' => '<a href="javascript:close();"> | </a>',
			'width' => '1024m',
			'height' => '800',
			'JSwindow' => '1',
			'JSwindow.' => array (
				'newWindow' => '1',
				'expand' => '0,0'
			)
		);

		$imageConf = array (
			'file.' => array (
				'maxW' => '600'
			),
			'params' => 'class="tx-terdochtml-clickenlarge"'
		);

		$imagesArr = t3lib_div::getFilesInDir ($documentDir.'docbook/pictures/', 'png,gif,jpg');
		if (is_array ($imagesArr)) {
			$picturesRelativePath = substr ($documentDir, strlen (PATH_site)).'docbook/pictures/';				
			foreach ($imagesArr as $filename) {
				if (strstr ($html, $filename)) {
					$imageDimensionsArr = $gifBuilderObj->getImageDimensions($documentDir.'docbook/pictures/'.$filename);

						// Very small images are not rendered with GIFBUILDER: 
					if ($imageDimensionsArr[0] < 100 && $imageDimensionsArr[1] < 100) {			
						$renderedImageTag = '<img class="tx-terdochtml-inline" src="'.$picturesRelativePath.$filename.'" width="'.$imageDimensionsArr[0].'" height="'.$imageDimensionsArr[1].'" alt="" title="" />';								
					} else {
						$imageConf['file'] = $picturesRelativePath.$filename;
	
						$renderedImageTag =' 
							<div style="text-align: center; margin: 10px 0 10px 0;"> '.
								$pObj->cObj->imageLinkWrap ($pObj->cObj->IMAGE ($imageConf), $picturesRelativePath.$filename, $linkWrapConf).'
							</div>
						';					
					}
					$html = preg_replace ('/(<img src="\{TX_TERDOC_PICTURESDIR\}'.$filename.'" width="NaN" \/>)/', $renderedImageTag, $html);
				}
			}	
		}
		return $html;	
	}
	
	/**
	 * Returns an array with information about the previous, current
	 * and next chapter and section number.
	 * 
	 * @param	string		$extensionKey: The extension key
	 * @param	string		$version: Extension version string
	 * @param	object		$pObj: Reference to the plugin object (pi_base child)
	 * @return	array		Array with chapter and section information		
	 * @access	protected
	 */
	protected function getChapterSectionInformation ($extensionKey, $version, $pObj) {
		
		$documentDir = $this->getDocumentDirOfExtensionVersion ($extensionKey, $version);		

		$tocArr = unserialize (@file_get_contents ($documentDir.'toc.dat'));
		if (!is_array ($tocArr)) return array();;

		$currentChapter = $pObj->piVars['html_readonline_chapter'] ? intval($pObj->piVars['html_readonline_chapter']) : 1;
		$currentSection = $pObj->piVars['html_readonline_section'] ? intval($pObj->piVars['html_readonline_section']) : 1;
		
		if (!is_array($tocArr[$currentChapter])) $currentChapter = 1;
		if (!is_array($tocArr[$currentChapter]['sections'][$currentSection])) $currentSection = 1;

		if (is_array ($tocArr[$currentChapter]['sections'][$currentSection+1])) {
			$nextChapter = $currentChapter;
			$nextSection = $currentSection+1;	
		} elseif (is_array ($tocArr[$currentChapter+1])) {
			$nextChapter = $currentChapter+1;
			$nextSection = 1;
		}
		
		if (is_array ($tocArr[$currentChapter]['sections'][$currentSection-1])) {
			$previousChapter = $currentChapter;
			$previousSection = $currentSection-1;	
		} elseif (is_array ($tocArr[$currentChapter-1])) {
			$previousChapter = $currentChapter-1;
			if (is_array ($tocArr[$previousChapter]['sections'])) {
				$previousSection = count ($tocArr[$previousChapter]['sections']);
			} else {
				$previousSection = 1;	
			}			
		}
		return array (
			'currentChapter' => $currentChapter,
			'currentSection' => $currentSection,
			'previousChapter' => $previousChapter,
			'previousSection' => $previousSection,
			'nextChapter' => $nextChapter,
			'nextSection' => $nextSection
		);
	}

	/**
	 * Returns one manual record from tx_terdoc_manuals for the specified
	 * extension version
	 * 
	 * @param	string		$extensionKey: Extension key
	 * @param	string		$version: Version string of the extension 
	 * @return	mixed		One manual record as an array or FALSE if request was not succesful
	 * @access	protected 
	 */
	protected function db_fetchManualRecord ($extensionKey, $version) {
		global $TYPO3_DB;

		$res = $TYPO3_DB->exec_SELECTquery (
			'*',
			'tx_terdoc_manuals',
			'extensionkey="'.$extensionKey.'" AND version="'.$version.'"'
		);
		
		if ($res) {
			return $TYPO3_DB->sql_fetch_assoc ($res);
		} else return FALSE;
	}
	
	/**
	 * Removes directory with all files from the given path recursively! 
	 * Path must somewhere below typo3temp/
	 * 
	 * @param	string		$removePath: Absolute path to directory to remove
	 * @return	void		
	 * @access	protected
	 */
	protected function removeDirRecursively ($removePath)	{

			// Checking that input directory was within
		$testDir = PATH_site.'typo3temp/';
		if (t3lib_div::validPathStr($removePath) && !t3lib_div::isFirstPartOfStr ($removePath,$testDir)) die($removePath.' was not within '.$testDir);

			// Go through dirs:
		$dirs = t3lib_div::get_dirs($removePath);
		if (is_array($dirs))	{
			foreach($dirs as $subdirs)	{
				if ($subdirs)	{
					$this->removeDirRecursively($removePath.'/'.$subdirs.'/');
				}
			}
		}

			// Then files in this dir:
		$fileArr = t3lib_div::getFilesInDir($removePath,'',1);
		if (is_array($fileArr))	{
			foreach($fileArr as $file)	{
				if (!t3lib_div::isFirstPartOfStr($file,$testDir)) die($file.' was not within '.$testDir);	// Paranoid...
				unlink($file);
			}
		}
			// Remove this dir:
		rmdir($removePath);
	}

	/**
	 * Returns the full path of the document directory for the specified
	 * extension version. If the path does not exist yet, it will be created - 
	 * given that the typo3temp/tx_terdoc/documentscache/ dir exists.  
	 * 
	 * In the document directory all rendered documents are stored.
	 * 
	 * @param	string		$extensionKey: The extension key
	 * @param	string		$version: The version string
	 * @return	string		Full path to the document directory for the specified extension version
	 */
	protected function getDocumentDirOfExtensionVersion ($extensionKey, $version) {
		$firstLetter = strtolower (substr ($extensionKey, 0, 1));
		$secondLetter = strtolower (substr ($extensionKey, 1, 1));
		$baseDir = PATH_site.'typo3temp/tx_terdoc/documentscache/';

 		list ($majorVersion, $minorVersion, $devVersion) = t3lib_div::intExplode ('.', $version);
		$fullPath = $baseDir.$firstLetter.'/'.$secondLetter.'/'.strtolower($extensionKey).'-'.$majorVersion.'.'.$minorVersion.'.'.$devVersion;
						
		return $fullPath.'/';		
	}

	/**
	 * Processes the given string with htmlspecialchars and converts the result
	 * from utf-8 to the charset of the current frontend
	 * page 
	 * 
	 * @param	string	$string: The utf-8 string to convert
	 * @return	string	The converted string
	 * @access	protected
	 */
	protected function csConvHSC ($string) {
		return $GLOBALS['TSFE']->csConv(htmlspecialchars($string), 'utf-8');
	}

}
?>