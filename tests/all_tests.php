<?php
//require_once('simpletest/autorun.php');
    require_once('simpletest/unit_tester.php');
    require_once('simpletest/reporter.php');

class ShowPasses extends HtmlReporter {
    
    function paintPass($message) {
        parent::paintPass($message);
        print "&<span class=\"pass\">Pass</span>: ";
        $breadcrumb = $this->getTestList();
        array_shift($breadcrumb);
        print implode("-&gt;", $breadcrumb);
        print "-&gt;$message<br />\n";
    }
    
    function _getCss() {
        return parent::_getCss() . ' .pass { color: green; }';
    }
}

class ShowSimplePasses extends TextReporter {
	var $lastfile = '';
    
    function paintPass($message) {
        parent::paintPass($message);
        $breadcrumb = $this->getTestList();
        array_shift($breadcrumb);
	if ($this->lastfile != $breadcrumb[0]) {
		print "$breadcrumb[0]\n";
		$this->lastfile = $breadcrumb[0];
	}
        array_shift($breadcrumb);
        print "\t" . implode(":", $breadcrumb).": OK\n";
        //print ": $message\n";
    }
/*
    function paintFail($message) {
        parent::paintFail(null);
        $breadcrumb = $this->getTestList();
        array_shift($breadcrumb);
	if ($this->lastfile != $breadcrumb[0]) {
		print "$breadcrumb[0]\n";
		$this->lastfile = $breadcrumb[0];
	}
        array_shift($breadcrumb);
        print "\t" . implode(":", $breadcrumb).": $message\n";
    }
*/
}


class AllTests extends TestSuite {
    function AllTests() {
        $this->TestSuite('All tests');
        $this->addFile('tests/activerecord.php');
//        $this->addFile('tests/livetest.php');
    }
}

$test = new AllTests();
$test->run(new ShowSimplePasses());

?>
