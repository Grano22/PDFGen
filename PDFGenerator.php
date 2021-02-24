<?php
/**
 * PDF Generator API by Grano22 Dev based on PHP Libraries
 * @package ABTheme
 * @author Grano22 Dev
 */
/*ini_set('display_errors', 1);
error_reporting(~0);*/
error_reporting(0);
@ini_set('display_errors', 0);

include get_template_directory() . "/rest/libraries/TCPDF/tcpdf_import.php";
include get_template_directory() . "/rest/libraries/dompdf/autoload.inc.php";
use Dompdf\Dompdf;

define("IMAGES_EXTENSIONS", ["png", "jpg", "webp", "gif", "tiff", "psd", "bmp", "svg"]);

class MinLoggerError {
    private $errNum = -1;
    private $name = "";
    private $description = "";
    private $creationTimestamp = -1;

    function __construct($errNum=-1, $name="", $des="") {
        $this->errNum = $errNum;
        $this->name = $name;
        $this->description = $des;
        $this->creationTimestamp = time();
    }
}

class MinLogger {
    private $errors = [];

    function addError(int $num=-1, string $name, string $description) {
        array_push($this->errors, new MinLoggerError($num, $name, $description));
    }

    function toString() {
        return json_encode($this->errors);
    }
}

$GLOBALS['logger'] = new MinLogger();

class Input_PDFPreparator {
    function getTagOfHTMLElement(string $attrName, string $matchedHTMLString) {
        $completor = ""; $inTag = '"';
        for($chr=0;$chr<strlen($matchedHTMLString);$chr++) {
            if($inTag!='"') {
                if($matchedHTMLString[$chr]=='"') return $inTag; else $inTag .= $matchedHTMLString[$chr];
            }
            else if($completor==$attrName) {
                if($matchedHTMLString[$chr]=='"') $inTag = ""; else if($matchedHTMLString[$chr]=='=') { if($matchedHTMLString[$chr+1]!='"') return false; } else if(trim($matchedHTMLString[$chr])=="" || $matchedHTMLString[$chr]=='>') return true; else return false;
            } else if(trim($matchedHTMLString[$chr])=='') $completor = "";
            else $completor .= $matchedHTMLString[$chr];
        }
        return null;
    }

    function getContentBetweenTags(string $matchedHTMLString) : string {
        $inTag = false; $inStr = false; $completor = "";
        for($chr=0;$chr<strlen($matchedHTMLString);$chr++) {
            if($inStr) { if($inTag) return ""; $inStr = false; }
            else if($matchedHTMLString[$chr]=='"' && !$inTag) $inStr = true;
            else if($inTag && $matchedHTMLString[$chr]=="<" && $matchedHTMLString[$chr+1]=="/") return $completor;
            else if($inTag) $completor += $matchedHTMLString[$chr];
            else if($matchedHTMLString[$chr]==">") $inTag = true;
        }
        return $completor;
    }

    function separateClosedTag(string $matchedHTMLString) : array {
        $tagParts = [];
        $inTag = 0; $inStr = false; $completor = "";
        for($chr=0;$chr<strlen($matchedHTMLString);$chr++) {
            if($inTag==0) {
                if($matchedHTMLString[$chr]=="<") { $inTag = 1; $completor += $matchedHTMLString[$chr]; }
            } else if($inTag==1) {
                if($matchedHTMLString[$chr]=='"') $inStr = !$inStr;
                else if(!$inStr && $matchedHTMLString[$chr]==">" && $inStr==false) { array_push($tagParts, $completor); $completor = ""; }
            } else if($inTag==2) {
                if($matchedHTMLString[$chr]=="<" && $matchedHTMLString[$chr+1]=="/") { array_push($tagParts, $completor); $completor = ""; }
            } else if($inTag==3) {
                if($matchedHTMLString[$chr]==">") { $completor += $matchedHTMLString[$chr]; array_push($tagParts, $completor); $completor = ""; }
            }
            $completor += $matchedHTMLString[$chr];
        }
        return $tagParts;
    }

    function getStringbetweenSameChars(string $matchedHTMLString, string $charBefore='"', string $charAfter='') : string {
        $between = false; $completor = "";
        if($charAfter=='') $charAfter = $charBefore;
        for($chr=0;$chr<strlen($matchedHTMLString);$chr++) {
            if($between && $matchedHTMLString[$chr]==$charAfter) return $completor;
            else if($between) $completor .=  $matchedHTMLString[$chr];
            if($matchedHTMLString[$chr]==$charBefore) $between = true;
        }
        return "";
    }

    function prepare(string $input, array $args=[]) : string {return $input;}
}

class GetSourcesOfImages_PDFPreparator extends Input_PDFPreparator { //<img \s*src=\"[^"]+\"\s*(([A-z-]*\=\"([^"]*)\"\s*)+)?\s*\/?\>
    function prepare(string $input, array $args=[]) : string {
        preg_match_all('/<img \s*(([A-z-]*\=\"([^"]*)\"\s*)+)?\s*\/?\>/', $input, $matchedImagesWithSources);
        //$matchedImagesWithSources = array_filter(array_map('array_filter', $matchedImagesWithSources));
        if(is_array($matchedImagesWithSources)) {
            foreach($matchedImagesWithSources[0] as $matchedImageSource) {
                $srcAttr = $this->getTagOfHTMLElement("src", $matchedImageSource);
                if($srcAttr) {
                    $srcExt = pathinfo($srcAttr, PATHINFO_EXTENSION);
                    if(in_array($srcExt, IMAGES_EXTENSIONS)) {
                        $prepSrc = "data:image/$srcExt;base64,".base64_encode(file_get_contents($srcAttr));
                        $prepContents = str_replace("src=\"$srcAttr\"", "src=\"$prepSrc\"", $matchedImageSource);
                        $input = str_replace($matchedImageSource, $prepContents, $input);
                    }
                }
            }
        }
        return $input;
    }
}

class GetSourcesOfLinks_PDFPreparator extends Input_PDFPreparator { //<link \s*href=\"[^"]+\"\s*(([A-z-]*\=\"([^"]*)\"\s*)+)?\s*\/?\>
    function prepare(string $input, array $args=[]) : string {
        try {
            preg_match_all('/<link \s*(([A-z-]*\=\"([^"]*)\"\s*)+)?\s*\/?\>/', $input, $matchedLinksWithSources);
            //$matchedLinksWithSources = array_filter(array_map('array_filter',  $matchedLinksWithSources));
            if(is_array($matchedLinksWithSources)) {
                foreach($matchedLinksWithSources[0] as $matchedLinkSource) {
                    $srcAttr = $this->getTagOfHTMLElement("href", $matchedLinkSource);
                    if($srcAttr) {
                        $srcExt = pathinfo($srcAttr);
                        if($srcExt['extension']=="css") {
                            try {
                                /*set_error_handler(function ($err_severity, $err_msg, $err_file, $err_line, array $err_context) {
                                    throw new ErrorException( $err_msg, 0, $err_severity, $err_file, $err_line );
                                }, E_WARNING);*/
                                $prepSrc = file_get_contents($srcAttr);
                                if(!is_string($prepSrc)) $prepSrc = '/* Style cannot be loaded from url: '.$srcAttr.' */';
                                $prepSrc = '<style type="text/css">'.$prepSrc.'</style>';
                                $input = str_replace($matchedLinkSource, $prepSrc, $input);
                            } catch(Throwable $e) {
                                $GLOBALS['logger']->addError(3, "Source not found during preparsing", "Source not found at $srcAttr");
                            }
                        }
                    }
                }
            }
        } catch(Exception $e) {

        }
        return $input;
    }
}

class DefaultFontToStyle_PDFPreparator extends Input_PDFPreparator {
    function prepare(string $input, array $args=[]) : string {
        $headTag = preg_match('/<head\s*(([A-z-]*\=\"([^"]*)\"\s*)+)?\>(.*?)\<\/head\>/', $input, $headTagMatches);
        if($headTag) {
            $headIn = $this->separateClosedTag($headTag);
            $input = str_replace($headTagMatches[0], $headIn[0].$headIn[1].'<style type="text/css"> body { font-family: DejaVu Sans, sans-serif; } </style>'.$headIn[2], $input);
        } else $input = '<style type="text/css"> body { font-family: DejaVu Sans, sans-serif; } </style>'.$input;
        return $input;
    }
}

class RelativePath_PDFPreparator extends Input_PDFPreparator {
    function prepare(string $input, array $args=[]) : string {
        if($args) {

        } else return str_replace(get_template_directory_uri() , "", $input);
    } //home_url( "/" )
}

class RegexpPattern_PDFPreparator {
    function prepare(string $input, array $args=[]) : string {
        if(array_key_exists("p", $args)) { 
            if(array_key_exists("r", $args)) {
                $input = preg_replace($args["p"], $args["r"], $input);
            }
        } 
        return $input;
    }
}

class CSSImagesAllPathsToUrls_PDFPreparator extends Input_PDFPreparator {
    function prepare(string $input, array $args=[]) : string {
        $paths = [
            "img"=>"/",
            "ttf"=>"/static/"
        ];
        preg_match_all('/url\((\"|\'|)(\.|\.\.|)(\/|)([A-ź0-9_\- \/]*)\.(png|jpe?g|webp)(\"|\'|)\)/', $input, $allStylesheetLinks);
        foreach($allStylesheetLinks[0] as $stylesheetURL) {
            if(strpos('"', $stylesheetURL)!==false) {
                $strBetweenChars = $this->getStringbetweenSameChars($stylesheetURL, '"');
                if($strBetweenChars) {
                    /*$urlExt = pathinfo($strBetweenChars, PATHINFO_EXTENSION);*/
                    $pathParts = explode("/", $strBetweenChars);
                    $pathParts = array_filter($pathParts, function($val) { return $val!=='..' && $val!=="." && $val!==""; });
                    $strBetweenChars = get_template_directory_uri()."/".implode("/", $pathParts);
                    $input = str_replace($stylesheetURL, 'url("'.$strBetweenChars.'")', $input);
                }
            } else {
                $strBetweenChars = $this->getStringbetweenSameChars($stylesheetURL, '(', ')');
                if($strBetweenChars) {
                    $pathParts = explode("/", $strBetweenChars);
                    $pathParts = array_filter($pathParts, function($val) { return $val!=='..' && $val!=="." && $val!==""; });
                    $strBetweenChars = get_template_directory_uri()."/".implode("/", $pathParts);
                    $input = str_replace($stylesheetURL, 'url('.$strBetweenChars.')', $input);
                }
            }
        }
        return $input;
    }
}

class PDFGenerator {
    private $currLibrary = "";
    private $libraryContext = null;

    public $pagesSnapshots = [];
    public $loadedHTMLSnapshots = [];

    private $queuedStyles = [];

    private $pdfState = 0; //0 - nothing

    private $totalToPrepare = "";
    private $totalHTML = "";

    //Filters
    private $filtersList = [];

    private $config = [];

    const ORIENTATION_PORTRAIT = [
        "TCPDF" => "P",
        "DomPDF" => "portrait"
    ];

    const FORMAT = [
        "TCPDF" => "A4",
        "DomPDF" => "A4"
    ];

    const UTF8_ENCODING = [
        "TCPDF" => "UTF-8",
        "DomPDF" => "UTF-8"
    ];

    function __construct(array $assocArgs=[], string $library="TCPDF") {
        $this->currLibrary = $library;
        $this->config = $this->initialiseInput($assocArgs);
        switch($library) {
            case "DomPDF":
                $this->prepareDocumentDomPDF();
            break;
            case "TCPDF":
            default:
                $this->prepareDocumentTCPDF($this->config["orientation"], $this->config["unit"], $this->config["format"], $this->config["uniCode"], $this->config["encoding"], $this->config["diskcache"], $this->config["pdfa"]);
        }
    }

    function __get($name) {
        switch($name) {
            case "isDocumentReady":
                return $this->pdfState>=2;
        }
    }

    function initialiseInput(array $assocArgs=[]) {
        return array_merge([
            //Document Presents
            "orientation" => PDFGenerator::ORIENTATION_PORTRAIT[$this->currLibrary],
            "format" => PDFGenerator::FORMAT[$this->currLibrary],
            "encoding" => PDFGenerator::UTF8_ENCODING[$this->currLibrary],
            "unit" => "mm",
            "uniCode" => true,
            "diskcache" => false,
            "pdfa" => false,
            //Document Meta
            "author" => "Unkown",
            "title" => "Untitled",
            "subject" => "Unknown",
            "keywords" => "",
            //Document Header
            "addHeader" => false,
            "headerLogo" => "",
            "headerLogoWidth" => "",
            "headerTitle" => "Untitled",
            "headerSubtle" => "",
            "headerBackground" => [ 0, 0, 0 ],
            "headerTextColor" => [ 0, 0, 0 ],
            //Document Body
            "documentFont" => 'helvetica'
        ], $assocArgs);
    }

    /**
     * Library TCPDF
     */
    function prepareDocumentTCPDF(string $orientation=PDFGenerator::ORIENTATION_PORTRAIT["TCPDF"], string $unit="mm", string $format="A4", bool $uniCode=true, string $encoding="UTF-8", bool $diskcache=false, bool $pdfa=false) {
        $this->libraryContext = new TCPDF($orientation, $unit, $format, $uniCode, $encoding, $diskcache, $pdfa);
        $this->pdfState = 1;
        //Doc Meta
        $this->libraryContext->SetCreator("PDFGenerator by Grano22 with used TCPDF Library by tecnickcom");
        $this->libraryContext->SetAuthor($this->config["author"]);
        $this->libraryContext->SetTitle($this->config["title"]);
        $this->libraryContext->SetSubject($this->config["subject"]);
        $this->libraryContext->SetKeywords($this->config["keywords"]);

        if($this->config["addHeader"]) { 
            $this->libraryContext->SetHeaderData($this->config["headerLogo"], $this->config["headerLogoWidth"], $this->config["headerTitle"], $this->config["headerSubtle"], $this->config["headerBackground"], $this->config["headerTextColor"]);
            $this->libraryContext->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        }

        $this->libraryContext->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        $this->libraryContext->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        $this->libraryContext->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $this->libraryContext->SetHeaderMargin(PDF_MARGIN_HEADER);
        $this->libraryContext->SetFooterMargin(PDF_MARGIN_FOOTER);

        $this->libraryContext->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        $this->libraryContext->setImageScale(PDF_IMAGE_SCALE_RATIO);

        $this->libraryContext->SetFont($this->config["documentFont"], '', 10);
        $this->pdfState = 2;
    }

    function addPageTCPDF(string $htmlContent="") {
        $this->libraryContext->addPage();
        $htmlContent = implode("", $this->queuedStyles).$htmlContent;
        array_push($this->pagesSnapshots, $htmlContent);
        $this->libraryContext->writeHTML($htmlContent, true, false, true, false, '');
        $this->libraryContext->lastPage();
    }

    function toOutputTCPDF() {
        return $this->libraryContext->Output("pretty.pdf", "S");
    }

    function loadHTMLTCPDF(string $htmlContent="") {
        $htmlAsPages = preg_split('/<div \s*class="page_break"\s*([A-z-]*\=\"([A-ź0-9 _-]*)\"\s*)+\></div>/', $htmlContent);
        foreach($htmlAsPages as $htmlPage) $this->addPageTCPDF($htmlPage);
    }
    //END Library TCPDF

    /**
     * Library DomPDF
     */
    function prepareDocumentDomPDF() {
        $this->libraryContext = new Dompdf([ "dpi" => '96' ]);
        $options = $this->libraryContext->getOptions();
        $options->set(["chroot" => get_template_directory(), "isRemoteEnabled" => true, 'enable_css_float' => true ]); //get_template_directory_uri()
        $this->libraryContext->setOptions($options);
        //$this->libraryContext->setBasePath( get_template_directory() );
        $this->totalToPrepare = '<html><head><style id="domPDFCore" type="text/css"> .page_break { page-break-before: always; } </style>';
    }

    function addPageDomPDF(string $htmlContent="") {
        if(count($this->pagesSnapshots)>0) $htmlContent = '<div class="page_break"></div>'.$htmlContent;
        $this->totalToPrepare .=  $htmlContent;
        array_push($this->pagesSnapshots, $htmlContent);
    }

    function loadHTMLDomPDF(string $htmlContent="") {
        $this->totalToPrepare = $htmlContent;
    }

    function toOutputDomPDF() {
        //$this->totalToPrepare = str_replace(home_url( "/" ), "", $this->totalToPrepare);
        $this->totalToPrepare = $this->useFilters($this->totalToPrepare);
        if(strpos($this->totalToPrepare, "</head")!==false) {
            $this->totalToPrepare = preg_replace('/\<head\>/', '<head>'.implode("", $this->queuedStyles), $this->totalToPrepare);
        } else {
            $this->totalToPrepare = '<head>'.implode("", $this->queuedStyles).'</head>'.$this->totalToPrepare;
        }
        array_push($this->loadedHTMLSnapshots, $this->totalToPrepare);
        $this->libraryContext->loadHTML($this->totalToPrepare);
        $this->libraryContext->render();
        return $this->libraryContext->output(); //['compress'=>1, 'attachments'=>1]
    }

    //END Library DomPDF

    function generatePage() {

    }

    function addStyleToQueue(string $styleURL) {
        if($styleURL!="") { $styleContext = file_get_contents($styleURL);
        if(!is_string($styleContext)) $styleContext = '/* Source not found on URL: '.$styleURL.' */';
        array_push($this->queuedStyles, '<style type="text/css">'.$styleContext.'</style>'); }
    }

    function queueStyles(string $styleURLs) {
        $urlsList = explode(",", $styleURLs);
        foreach($urlsList as $styleURL) {
            if($styleURL!="") { $styleContext = file_get_contents($styleURL);
            if(!is_string($styleContext)) $styleContext = '/* Source not found on URL: '.$styleURL.' */';
            array_push($this->queuedStyles, '<style type="text/css">'.$styleContext.'</style>'); }
        }
    }

    /**
     * Add filter
     */
    function addFilter(string $classFilterName, array $args=[]) {
        if(class_exists($classFilterName."_PDFPreparator")) { if(count($args)>0) array_push($this->filtersList, [ "name"=>$classFilterName, "params"=>$args ]); else array_push($this->filtersList, $classFilterName); }
    }

    /**
     * Use Filter Once
     */
    function useFilter(string $targetStr, string $filterName, array $args=[]) {
        $classFilterName = $filterName."_PDFPreparator";
        if(class_exists($classFilterName."_PDFPreparator")) {
            $filterClass = new $classFilterName;
            $targetStr = $filterClass->prepare($filterClass, $args);
        }
        return $targetStr;
    }

    /**
     * Content filter
     */
    function useFilters(string $inputHTML) : string {
        foreach($this->filtersList as $filterName) {
            if(is_array($filterName)) {
                $classFilterName = $filterName["name"]."_PDFPreparator";
                $filterClass = new $classFilterName;
                $inputHTML = $filterClass->prepare($inputHTML, $filterName["params"]);
            } else {
                $classFilterName = $filterName."_PDFPreparator";
                $filterClass = new $classFilterName;
                $inputHTML = $filterClass->prepare($inputHTML);
            } 
        }
        return $inputHTML;
    }

    /**
     * Load HTML Native
     */
    function loadHTML(string $htmlContent="") {
        switch($this->currLibrary) {
            case "DomPDF":
                $this->loadHTMLDomPDF($htmlContent);
            break;
            case "TCPDF":
            default:
                $this->loadHTMLTCPDF($htmlContent);
        }
    }

    /**
     * addPage Native
     */
    function addPage(string $htmlContent="") {
        switch($this->currLibrary) {
            case "DomPDF":
                $this->addPageDomPDF($htmlContent);
            break;
            case "TCPDF":
            default:
                $this->addPageTCPDF($htmlContent);
        }
    }

    /**
     * toOutput Native
     */
    function toOutput() {
        switch($this->currLibrary) {
            case "DomPDF":
                return $this->toOutputDomPDF();
            case "TCPDF":
            default:
                return $this->toOutputTCPDF();
        }
    }

    /**
     * toURIOutput Native
     */
    function toURIOutput() {
        return "data:application/pdf;base64,".base64_encode($this->toOutput());
    }

    /**
     * Check if font exist
     */
    function isExistFont($fontName) {

    }

    /**
     * Install font
     */
    function installFont() {

    }
}
?>