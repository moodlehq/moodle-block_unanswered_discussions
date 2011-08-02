<?php

class block_unanswered_discussions extends block_base {
    // Default Configuration
    var $defaultconfig = array(
        // Default block title
        'title' => 'Unanswered Discussions',

        // The number of posts of each section to display
        'limit' => array(
            2, // Random Unanswered Posts
            2, // Oldest Unanswered Posts
            2  //   Your Unanswered Posts
            ),

        // Change the following to false to disable the section marked
        'enabled' => array(
            true, // Random Unanswered Posts
            true, // Oldest Unanswered Posts
            true  //   Your Unanswered Posts
            ),

        // Whether to show an image next to each entry
        'show_image' => true,

        // Use this to exclude fora from the selection process. Useful if you don't
        // want your "News" or "Announcements" forum showing up.
        //
        // You'll have to put the forum ids in here manually, though, seperated by a
        // comma (,). Open the forums page for your course and look at the link to the
        // forum you want to exclude. It should have an "f=<number>" at the end of it.
        // That number is the forum id.
        //
        // You can put fora from any course in here, they all have different ID's, so
        // mixing and matching is fine.
        //
        // It should end up looking something like:
        // var $exclude = array(4, 7, 11);
        // where forum 4 and 7 may be in course 2 and forum 11 may be in course 5.
        'exclude' => array()
    );

    function init() {
        $this->title = get_string('unanswereddiscussions', 'block_unanswered_discussions');
        $this->version = 2005091300;
    }

    function preferred_width() {
        return 210;
    }

    function specialization() {
        if (!isset($this->config)) {
            $this->config = new stdClass;
        } else {
            if (!isset($this->config->show_image)) {
                $this->config->show_image = false;
            }
        }

        foreach($this->defaultconfig as $name => $value) {
            if (!isset($this->config->$name)) {
                $this->config->$name = $value;
            }
        }

        if (isset($this->config->title)) {
            $this->title = $this->config->title;
        }
    }

    function get_data($course = 0) {
        global $CFG, $USER, $DB;

        // If we've already done it, return the results
        if (!empty($this->discussions)) {
            return $this->discussions;
        }

        $this->discussions = array();

        // Which course are we grabbing data for? Make sure it's an integer.
        $course = intval($course);

        if (empty($course)) {
            $course = 5;
        }

        // These are the different bits in the three queries
        $queries = array(
            'select' => array(',RAND() random', '', ',RAND() random'),
            'where' => array(" AND d.userid <> :userid AND p.modified >= :modified",
                             " AND d.userid <> :userid AND p.modified >= :modified",
                             " AND d.userid = :userid AND p.modified >= :modified"),
            'order' => array(',random', ',p.modified ASC', ',random'),
        );
        $params = array(
            'courseid' => $course,
            'userid' => $USER->id,
            'modified' => (time() - 259200)
        );

        /// Do it backwards and exclude previous results

        // This array holds previous discussion ids to exclude for the next
        // query (stops duplication)
        $post_exclude = array();
        for ($i = 2; $i >= 0; $i--) {
            // No point doing the query if it's not enabled anyways
            if (!$this->section_enabled($i)) {
                continue;
            }
            
            $sql  = 'SELECT p.id, p.subject, p.modified, p.discussion, p.userid,
                     d.name, d.timemodified, d.usermodified, d.groupid, (COUNT(p.id) - 1) replies';
            if (!empty($queries['select'][$i])) {
                $sql .= $queries['select'][$i];
            }
            $sql .= ' FROM {forum_posts} p 
                      JOIN {forum_discussions} d ON d.id = p.discussion
                      WHERE d.course = :courseid';
            if (!empty($this->config->exclude)) {
                list($wheresql, $whereparams) = $DB->get_in_or_equal($this->config->exclude, SQL_PARAMS_NAMED, 'fparam0000', false);
                $params = array_merge($params, $whereparams);
                $sql .= ' AND d.forum '.$wheresql;
            }
            if (!empty($post_exclude)) {
                list($wheresql, $whereparams) = $DB->get_in_or_equal($post_exclude, SQL_PARAMS_NAMED, 'dparam0000', false);
                $params = array_merge($params, $whereparams);
                $sql .= ' AND p.discussion '.$wheresql;
            }
            if (!empty($queries['where'][$i])) {
                $sql .= $queries['where'][$i];
            }
            $sql .= ' GROUP BY p.discussion
                      ORDER BY replies ASC '.$queries['order'][$i];

            $this->discussions[$i] = $DB->get_records_sql($sql, $params, 0, $this->section_limit($i));

            // If it didn't get any results it doesn't need any processing
            if (empty($this->discussions[$i])) {
                continue;
            }

            // Remove discussions with replies (can't be done in SQL).
            // Have to do it this way because of the wacky keys created by
            // using "get_records_sql"
            $num = 0;
            $max = count($this->discussions[$i]);
            $discussion = end($this->discussions[$i]);
            while($discussion->replies > 0 && $num++ < $max) {
                array_pop($this->discussions[$i]);
                prev($this->discussions[$i]);
            }

            // Add each discussion to the exclusion list
            reset($this->discussions[$i]);
            foreach($this->discussions[$i] as $discussion) {
                $post_exclude[] = $discussion->discussion;
            }
        }

        return $this->discussions;
    }

    function section_enabled($index) {
        return (!empty($this->config->enabled[$index]));
    }

    function section_limit($index) {
        return (!empty($this->config->limit[$index]) ? intval($this->config->limit[$index]) : $this->defaultconfig['limit'][$index]);
    }

    function get_content() {
        global $CFG, $USER, $DB;

        // Don't do it more than once
        if ($this->content !== NULL) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';

        if (empty($this->instance)) {
            return $this->content;
        }

        // Get info about our current course
        $course = $this->page->course;//$DB->get_record('course', array('id' => $this->page->course->id));

        require_once($CFG->dirroot.'/mod/forum/lib.php');   // We'll need this

        // Do the data retreival. If we don't get anything, show a pretty
        // message instead and return.
        if (! $discussions = $this->get_data($course->id) ) {
            $this->content->text .= '('.get_string('nounanswereddiscussions', 'block_unanswered_discussions').')';
            return $this->content;
        }

        /// Actually create the listing now

        $strftimedatetime = get_string('strftimedatetime');
        $strtitle = array(
            get_string('randomposts', 'block_unanswered_discussions'),
            get_string('oldestposts', 'block_unanswered_discussions'),
            get_string('yourposts', 'block_unanswered_discussions')
        );

        // Make sure our sections are in order
        ksort($this->discussions);
        reset($this->discussions);

        // We're using a foreach here... why not?
        foreach ($this->discussions as $key => $set) {
            // If this section's not enabled, or empty, skip it
            if (!$this->section_enabled($key) || empty($set))
                continue;

            // Add the title for this section
            $this->content->text .= '<h5>'.$strtitle[$key].'</h5>';

            // Make sure we get them all by resetting the array pointer
            reset($set);

            // Print each discussion
            foreach ($set as $discussion) {
                $discussion->subject = $discussion->name;
                $discussion->subject = format_string($discussion->subject, true, $course->id);

                $this->content->text
                    .=  '<a href="'.$CFG->wwwroot.'/mod/forum/discuss.php?d='.$discussion->discussion.'">'
                    .  $discussion->subject
                    .  '</a> '
                    .  '<span class="date">'.userdate($discussion->modified, $strftimedatetime).'</span>'
                    .  '<br />';
            }

        }

        return $this->content;
    }

    function instance_allow_config() {
        return true;
    }
}
