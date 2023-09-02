<?php
// t_tags.php -- HotCRP tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Tags_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
    }

    function test_mutual_automatic_search() {
        assert_search_all_papers($this->u_chair, "#up", "");
        assert_search_all_papers($this->u_chair, "#withdrawn", "");
        assert_search_all_papers($this->u_chair, "tcpanaly", "15");

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "automatic_tag": [
                {"tag": "up", "search": "!#withdrawn AND tcpanaly"},
                {"tag": "withdrawn", "search": "status:withdrawn"}
            ]
        }');
        xassert($sv->execute());

        assert_search_all_papers($this->u_chair, "#up", "15");
        assert_search_all_papers($this->u_chair, "#withdrawn", "");

        xassert_assign($this->u_chair, "paper,action,notify\n15,withdraw,no\n");

        $p15 = $this->conf->checked_paper_by_id(15);
        xassert_gt($p15->timeWithdrawn, 0);

        assert_search_all_papers($this->u_chair, "#up", "");
        assert_search_all_papers($this->u_chair, "#withdrawn", "15");

        xassert_assign($this->u_chair, "paper,action,notify\n15,revive,no\n");

        assert_search_all_papers($this->u_chair, "#up", "15");
        assert_search_all_papers($this->u_chair, "#withdrawn", "");

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "automatic_tag": [
                {"tag": "up", "delete": true},
                {"tag": "withdrawn", "delete": true}
            ]
        }');
        xassert($sv->execute());

        assert_search_all_papers($this->u_chair, "#up", "");
        assert_search_all_papers($this->u_chair, "#withdrawn", "");
    }

    function test_mutual_valued_automatic_search() {
        assert_search_all_papers($this->u_chair, "#nau", "");
        assert_search_all_papers($this->u_chair, "#lotsau", "");

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "automatic_tag": [
                {"tag": "nau", "search": "all", "value": "au"},
                {"tag": "lotsau", "search": "#nau>3"}
            ]
        }');
        xassert($sv->execute());

        assert_search_all_papers($this->u_chair, "#nau 1-10 sort:id", "1 2 3 4 5 6 7 8 9 10");
        xassert_eqq($this->conf->checked_paper_by_id(1)->tag_value("nau"), 4.0);
        assert_search_all_papers($this->u_chair, "#lotsau 1-10 sort:id", "1 2 4 6 10");

        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "automatic_tag": [
                {"tag": "nau", "delete": true},
                {"tag": "lotsau", "delete": true}
            ]
        }');
        xassert($sv->execute());

        assert_search_all_papers($this->u_chair, "#nau", "");
        assert_search_all_papers($this->u_chair, "#lotsau", "");
    }

    function test_tag_patterns() {
        $sv = (new SettingValues($this->u_chair))->add_json_string('{
            "tag_readonly": "t*",
            "tag_hidden": "top",
            "tag_sitewide": "t*"
        }');
        xassert($sv->execute());

        $ti = $this->conf->tags()->find("tan");
        xassert($ti->is(TagInfo::TF_READONLY));
        xassert(!$ti->is(TagInfo::TF_HIDDEN));
        xassert($ti->is(TagInfo::TF_SITEWIDE));
        $ti = $this->conf->tags()->find("top");
        xassert($ti->is(TagInfo::TF_READONLY));
        xassert($ti->is(TagInfo::TF_HIDDEN));
        xassert($ti->is(TagInfo::TF_SITEWIDE));
        $ti = $this->conf->tags()->find("tan");
        xassert($ti->is(TagInfo::TF_READONLY));
        xassert(!$ti->is(TagInfo::TF_HIDDEN));
        xassert($ti->is(TagInfo::TF_SITEWIDE));
    }

    function test_assign_delete_create() {
        $p1 = $this->conf->checked_paper_by_id(1);
        xassert(!$p1->has_tag("testtag"));

        // set
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 0.0);

        // clear
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#clear\n");
        $p1->load_tags();
        xassert(!$p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), null);

        // set with value
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#2\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 2.0);

        // set without value when value exists
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 2.0);

        // set -> clear -> set resets value
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag\ntag,1,testtag#clear\ntag,1,testtag\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 0.0);

        // clear -> set
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#clear\ntag,1,testtag\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 0.0);

        // test #some value
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#2\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 2.0);

        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#clear\ntag,1,testtag#some\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 2.0);

        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#clear\n");
        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#some\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 0.0);

        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#3\ntag,1,testtag#some\n");
        $p1->load_tags();
        xassert($p1->has_tag("testtag"));
        xassert_eqq($p1->tag_value("testtag"), 3.0);

        xassert_assign($this->u_chair, "action,paper,tag\ntag,1,testtag#clear\n");
    }
}
