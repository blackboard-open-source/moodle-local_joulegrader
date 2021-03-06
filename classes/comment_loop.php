<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Comment loop
 *
 * @author    Sam Chaffee
 * @package   local_joulegrader
 * @copyright Copyright (c) 2015 Open LMS (https://www.openlms.net)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_joulegrader;
defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');

/**
 * @author Sam Chaffee
 * @package local/joulegrader
 */
class comment_loop implements \renderable {

    /**
     * @var gradingarea\gradingarea_abstract - instance
     */
    protected $gradingarea;

    /**
     * @var \comment - instance of comment class
     */
    protected $commentapi;

    /**
     * @var array - comments that belong to this comment loop
     */
    protected $comments;

    /**
     * @var array - user ids of users that should be included in this comment loop.
     */
    protected $commentusers;

    /**
     * @var \moodleform - comment form
     */
    protected $mform;

    /**
     * @var int - most recent comment item id
     */
    protected $commentitemid;

    /**
     * @param $gradingarea - gradingarea\gradingarea_abstract
     */
    public function __construct($gradingarea) {
        $this->gradingarea = $gradingarea;
    }

    /**
     * Initializes the commentapi data member by creating an instance the comment class
     *
     * @param null|\stdClass $options
     */
    public function init($options = null) {
        global $CFG;
        require_once($CFG->dirroot . '/comment/lib.php');

        if (is_null($options)) {
            $options = $this->gradingarea->get_comment_info();
        }

        $this->commentapi = new \comment($options);
    }

    /**
     * @return array - the comments for this loop
     */
    public function get_comments() {
        if (is_null($this->comments)) {
            $this->load_comments();
        }
        return $this->comments;
    }

    /**
     * @return \moodleform - comment form
     */
    public function get_mform() {
        if (is_null($this->mform)) {
            $this->load_mform();
        }
        return $this->mform;
    }

    /**
     * @return bool
     */
    public function user_can_comment() {
        return $this->commentapi->can_post();
    }

    /**
     * @param int $commentid
     * @return bool
     */
    public function user_can_delete($commentid) {
        global $DB, $USER;
        $comment = $DB->get_record('comments', array('id' => $commentid), '*', MUST_EXIST);

        return ($USER->id == $comment->userid) || $this->commentapi->can_delete($commentid);
    }

    /**
     * @param int $commentid
     *
     * @return bool
     */
    public function delete_comment($commentid) {
        return $this->commentapi->delete($commentid);
    }

    /**
     * @param \stdClass $commentdata - data from submitted comment form
     * @return comment
     */
    public function add_comment($commentdata) {
        $commentelement = 'comment_'.$this->gradingarea->get_areaid().'_'.$this->gradingarea->get_guserid();

        // Store for comment_post_insert callback.
        $this->commentitemid = $commentdata->{$commentelement}['itemid'];

        // Add the comment via the comment object.
        $commentrecord = $this->commentapi->add($commentdata->{$commentelement}['text'], FORMAT_MOODLE, array($this, 'comment_post_insert'));

        // Instantiate a joule grader comment object.
        $comment = new comment($commentrecord);

        $context = $this->gradingarea->get_gradingmanager()->get_context();

        // set the context
        $comment->set_context($context);
        $comment->set_gareaid($this->gradingarea->get_areaid());
        $comment->set_guserid($this->gradingarea->get_guserid());

        return $comment;
    }

    /**
     * Callback from comment:add() to handle files.
     *
     * @param \stdClass $comment
     * @return \stdClass
     */
    public function comment_post_insert(\stdClass $comment) {
        global $DB;

        $itemid = $this->commentitemid;
        $context = $this->gradingarea->get_gradingmanager()->get_context();
        $fileareainfo = $this->gradingarea->get_comment_filearea_info();
        $editoroptions = $this->gradingarea->get_editor_options();
        $content = file_save_draft_area_files($itemid, $context->id, $fileareainfo->component, $fileareainfo->filearea,
            $comment->id, $editoroptions, $comment->content);

        if ($content != $comment->content) {
            $DB->update_record('comments', (object) array('id' => $comment->id, 'content' => $content));
            $comment->content = $content;
        }

        $this->commentitemid = null;

        return $comment;
    }

    /**
     * Loads the comments for this loop
     *
     * @return void
     */
    protected function load_comments() {
        // Initialize
        $this->comments = array();

        if (!$this->commentapi instanceof \comment) {
            $this->init();
        }

        $comments = $this->commentapi->get_comments();
        $context = $this->commentapi->get_context();
        if (!empty($comments)) {
            $comments = array_reverse($comments);

            // Iterate through comments and instantiate local_joulegrader\comment objects.
            foreach ($comments as $comment) {
                $commentobject = new comment($comment);
                $commentobject->set_context($context);
                $commentobject->set_gareaid($this->gradingarea->get_areaid());
                $commentobject->set_guserid($this->gradingarea->get_guserid());
                $this->comments[] = $commentobject;
            }
        }
    }

    /**
     * Loads the mform for adding comments to this loop
     *
     * @return void
     */
    protected function load_mform() {
        global $COURSE;

        //get info necessary for form action url
        $gareaid = $this->gradingarea->get_areaid();
        $guserid = $this->gradingarea->get_guserid();

        //build the form action url
        $urlparams = array('courseid' => $COURSE->id, 'action' => 'addcomment', 'garea' => $gareaid, 'guser' => $guserid);
        $mformurl = new \moodle_url('/local/joulegrader/view.php', $urlparams);

        //instantiate the form
        $this->mform = new form\comment($mformurl, $this->gradingarea);
    }
}
