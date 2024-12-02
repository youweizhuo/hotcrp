<?php
// api_paper.php -- HotCRP paper API call
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class Paper_API extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $disable_users = false;
    /** @var bool */
    private $dry_run = false;
    /** @var bool */
    private $single = false;
    /** @var ?ZipArchive */
    private $ziparchive;
    /** @var ?string */
    private $docdir;

    /** @var bool */
    private $ok = true;
    /** @var list<list<string>> */
    private $change_lists = [];
    /** @var list<object> */
    private $papers = [];
    /** @var list<bool> */
    private $valid = [];
    /** @var int */
    private $npapers = 0;
    /** @var ?string */
    private $landmark;


    const PIDFLAG_IGNORE_PID = 1;
    const PIDFLAG_MATCH_TITLE = 2;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** @return JsonResult */
    static function run_get(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        if ($prow && ($pj = (new PaperExport($user))->paper_json($prow))) {
            return new JsonResult(["ok" => true, "papers" => [$pj]]);
        }

        if (isset($qreq->p)) {
            return Conf::paper_error_json_result($qreq->annex("paper_whynot"));
        }

        if (!isset($qreq->q)) {
            return JsonResult::make_parameter_error("p");
        }

        $srch = new PaperSearch($user, ["q" => $qreq->q, "t" => $qreq->t, "sort" => $qreq->sort]);
        $pids = $srch->sorted_paper_ids();
        $prows = $srch->conf->paper_set([
            "paperId" => $pids,
            "options" => true, "topics" => true, "allConflictType" => true
        ]);

        $pex = new PaperExport($user);
        $pjs = [];
        foreach ($pids as $pid) {
            if (($pj = $pex->paper_json($prows->get($pid))))
                $pjs[] = $pj;
        }

        return new JsonResult([
            "ok" => true,
            "message_list" => $srch->message_list(),
            "papers" => $pjs
        ]);
    }

    /** @return JsonResult */
    private function run_post(Qrequest $qreq, ?PaperInfo $prow) {
        // if `p` param is set, must be a paper or "new"
        $this->single = $prow || isset($qreq->p);
        if ($this->single && !$prow && $qreq->p !== "new") {
            return Conf::paper_error_json_result($qreq->annex("paper_whynot"));
        }

        // set parameters
        if ($this->user->privChair) {
            if (friendly_boolean($qreq->disableusers)) {
                $this->disable_users = true;
            }
            if (friendly_boolean($qreq->notify) === false) {
                $this->notify = false;
            }
            if (friendly_boolean($qreq->addtopics)) {
                $this->conf->topic_set()->set_auto_add(true);
                $this->conf->options()->refresh_topics();
            }
        }
        if (friendly_boolean($qreq->dryrun)) {
            $this->dry_run = true;
        }

        // handle multipart or form-encoded data
        $ct = $qreq->body_content_type();
        if ($ct === "application/x-www-form-urlencoded"
            || $ct === "multipart/form-data") {
            return $this->run_post_form_data($qreq, $prow);
        }

        // from here on, expect JSON
        if ($ct === "application/json") {
            $jsonstr = $qreq->body();
        } else if ($ct === "application/zip") {
            $this->ziparchive = new ZipArchive;
            $cf = $qreq->body_filename(".zip");
            if (!$cf) {
                return JsonResult::make_error(500, "<0>Cannot read uploaded content");
            }
            $ec = $this->ziparchive->open($cf);
            if ($ec !== true) {
                return JsonResult::make_error(400, "<0>Bad ZIP file (error " . json_encode($ec) . ")");
            }
            list($this->docdir, $jsonname) = self::analyze_zip_contents($this->ziparchive);
            if (!$jsonname) {
                return JsonResult::make_error(400, "<0>ZIP `data.json` not found");
            }
            $jsonstr = $this->ziparchive->getFromName($jsonname);
        } else {
            return JsonResult::make_error(400, "<0>POST data must be JSON or ZIP");
        }

        $jp = Json::try_decode($jsonstr);
        if ($jp === null) {
            return JsonResult::make_error(400, "<0>Invalid JSON: " . Json::last_error_msg());
        } else if (is_object($jp)) {
            $this->single = true;
            return $this->run_post_single_json($prow, $jp);
        } else if ($this->single) {
            return JsonResult::make_error(400, "<0>Expected object");
        } else if (is_array($jp)) {
            return $this->run_post_multi_json($jp);
        } else {
            return JsonResult::make_error(400, "<0>Expected array of objects");
        }
    }


    /** @return JsonResult */
    private function run_post_form_data(Qrequest $qreq, ?PaperInfo $prow) {
        if (!$prow) {
            if (isset($qreq->sclass)
                && !$this->conf->submission_round_by_tag($qreq->sclass, true)) {
                return JsonResult::make_message_list(MessageItem::error($this->conf->_("<0>{Submission} class ‘{}’ not found", $qreq->sclass)));
            }
            $prow = PaperInfo::make_new($this->user, $qreq->sclass);
        }

        $ps = $this->paper_status();
        $ok = $ps->prepare_save_paper_web($qreq, $prow);
        $this->execute_save($ok, $ps);
        return $this->make_result();
    }

    /** @return JsonResult */
    private function run_post_single_json(?PaperInfo $prow, $jp) {
        if ($prow && !isset($jp->pid) && !isset($jp->id)) {
            $jp->pid = $prow->paperId;
        }
        if ($this->set_json_landmark(0, $jp, $prow ? $prow->paperId : null)) {
            $ps = $this->paper_status();
            $ok = $ps->prepare_save_paper_json($jp);
            $this->execute_save($ok, $ps);
        } else {
            $this->execute_fail();
        }
        return $this->make_result();
    }

    /** @param array $jps
     * @return JsonResult */
    private function run_post_multi_json($jps) {
        foreach ($jps as $i => $jp) {
            if ($this->set_json_landmark($i, $jp, null)) {
                $ps = $this->paper_status();
                $ok = $ps->prepare_save_paper_json($jp);
                $this->execute_save($ok, $ps);
            } else {
                $this->execute_fail();
            }
        }
        return $this->make_result();
    }


    /** @return PaperStatus */
    private function paper_status() {
        return (new PaperStatus($this->user))
            ->set_disable_users($this->disable_users)
            ->set_notify($this->notify)
            ->set_any_content_file(true)
            ->on_document_import([$this, "on_document_import"]);
    }

    /** @param PaperStatus $ps */
    private function execute_save($ok, $ps) {
        $this->ok = $this->ok && $ok;
        if ($this->ok && !$this->dry_run) {
            $this->ok = $ok = $ps->execute_save();
        }
        foreach ($ps->decorated_message_list() as $mi) {
            if (!$this->single && $this->landmark) {
                $mi->landmark = $this->landmark;
            }
            $this->append_item($mi);
        }
        $this->change_lists[] = $ps->changed_keys();
        if ($this->ok && !$this->dry_run) {
            if ($ps->has_change()) {
                $ps->log_save_activity("via API");
            }
            $pj = (new PaperExport($this->user))->paper_json($ps->saved_prow());
            $this->papers[] = $pj;
            ++$this->npapers;
        } else {
            $this->papers[] = null;
        }
        $this->valid[] = $ok;
    }

    private function execute_fail() {
        $this->ok = false;
        $this->change_lists[] = null;
        $this->papers[] = null;
        $this->valid[] = false;
    }

    /** @return JsonResult */
    private function make_result() {
        $jr = new JsonResult([
            "ok" => $this->ok,
            "message_list" => $this->message_list()
        ]);
        if ($this->single) {
            $jr->content["change_list"] = $this->change_lists[0];
            if ($this->npapers > 0) {
                $jr->content["paper"] = $this->papers[0];
            }
        } else {
            $jr->content["change_lists"] = $this->change_lists;
            if ($this->npapers > 0) {
                $jr->content["papers"] = $this->papers;
            }
            $jr->content["valid"] = $this->valid;
        }
        return $jr;
    }


    /** @param object $j
     * @param 0|1|2|3 $pidflags
     * @return null|int|'new' */
    static function analyze_json_pid(Conf $conf, $j, $pidflags = 0) {
        if (($pidflags & self::PIDFLAG_IGNORE_PID) !== 0) {
            if (isset($j->pid)) {
                $j->__original_pid = $j->pid;
            }
            unset($j->pid, $j->id);
        }
        if (!isset($j->pid)
            && !isset($j->id)
            && ($pidflags & self::PIDFLAG_MATCH_TITLE) !== 0
            && is_string($j->title ?? null)) {
            // XXXX multiple titles, look up only once?
            $pids = Dbl::fetch_first_columns($conf->dblink, "select paperId from Paper where title=?", simplify_whitespace($j->title));
            if (count($pids) === 1) {
                $j->pid = (int) $pids[0];
            }
        }
        $pid = $j->pid ?? $j->id ?? null;
        if (is_int($pid) && $pid > 0) {
            return $pid;
        } else if ($pid === null || $pid === "new") {
            return "new";
        } else {
            return null;
        }
    }

    private function set_json_landmark($index, $jp, $expected = null) {
        $pidish = self::analyze_json_pid($this->conf, $jp, 0);
        if (!$pidish) {
            $mi = $this->error_at(null, "Bad `pid`");
        } else if (($expected ?? $pidish) !== $pidish) {
            $mi = $this->error_at(null, "`pid` does not match");
        } else {
            $this->landmark = $pidish === "new" ? "index {$index}" : "#{$pidish}";
            return true;
        }
        if (!$this->single) {
            $mi->landmark = "index {$index}";
        }
        return false;
    }


    /** @return array{string,?string} */
    static function analyze_zip_contents($zip) {
        // find common directory prefix
        $dirpfx = null;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if ($dirpfx === null) {
                $xslash = (int) strrpos($name, "/");
                $dirpfx = $xslash ? substr($name, 0, $xslash + 1) : "";
            }
            while ($dirpfx !== "" && !str_starts_with($name, $dirpfx)) {
                $xslash = (int) strrpos($dirpfx, "/", -1);
                $dirpfx = $xslash ? substr($dirpfx, 0, $xslash + 1) : "";
            }
            if ($dirpfx === "") {
                break;
            }
        }

        // find JSONs
        $datas = $jsons = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!str_ends_with($name, ".json")
                || strpos($name, "/", strlen($dirpfx)) !== false
                || $name[strlen($dirpfx)] === ".") {
                continue;
            }
            $jsons[] = $name;
            if (preg_match('/\G(?:|.*[-_])data\.json\z/', $name, $m, 0, strlen($dirpfx))) {
                $datas[] = $name;
            }
        }

        if (count($datas) === 1) {
            return [$dirpfx, $datas[0]];
        } else if (count($jsons) === 1) {
            return [$dirpfx, $jsons[0]];
        } else {
            return [$dirpfx, null];
        }
    }

    /** @param object $docj
     * @param string $filename
     * @return bool */
    static function apply_zip_content_file($docj, $filename, ZipArchive $zip,
                                           PaperOption $o, PaperStatus $pstatus) {
        $stat = $zip->statName($filename);
        if (!$stat) {
            $pstatus->error_at_option($o, "{$filename}: File not found");
            return false;
        }
        // use resources to store large files
        if ($stat["size"] > 50000000) {
            if (PHP_VERSION_ID >= 80200) {
                $content = $zip->getStreamIndex($stat["index"]);
            } else {
                $content = $zip->getStream($filename);
            }
        } else {
            $content = $zip->getFromIndex($stat["index"]);
        }
        if ($content === false) {
            $pstatus->error_at_option($o, "{$filename}: File not found");
            return false;
        }
        if (is_string($content)) {
            $docj->content = $content;
            $docj->content_file = null;
        } else {
            $docj->content_file = $content;
        }
        if (!isset($docj->filename)) {
            $slash = strpos($filename, "/");
            $docj->filename = $slash > 0 ? substr($filename, $slash + 1) : $filename;
        }
        return true;
    }

    function on_document_import($docj, PaperOption $o, PaperStatus $pstatus) {
        if ($docj instanceof DocumentInfo
            || !isset($docj->content_file)) {
            return;
        } else if (is_string($docj->content_file ?? null)
                   && $this->ziparchive) {
            return self::apply_zip_content_file($docj, $this->docdir . $docj->content_file, $this->ziparchive, $o, $pstatus);
        } else {
            unset($docj->content_file);
        }
    }


    /** @return JsonResult */
    static function run(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $old_overrides = $user->overrides();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        if ($qreq->is_get()) {
            $jr = self::run_get($user, $qreq, $prow);
        } else {
            $jr = (new Paper_API($user))->run_post($qreq, $prow);
        }
        $user->set_overrides($old_overrides);
        if (($jr->content["message_list"] ?? null) === []) {
            unset($jr->content["message_list"]);
        }
        return $jr;
    }
}
