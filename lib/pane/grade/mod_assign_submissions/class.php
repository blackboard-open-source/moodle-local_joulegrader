<?php
defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');
require_once($CFG->dirroot . '/local/joulegrader/lib/pane/grade/abstract.php');
/**
 * joule Grader mod_assign_submissions grade pane class
 *
 * @author Sam Chaffee
 * @package local/joulegrader
 */
class local_joulegrader_lib_pane_grade_mod_assign_submissions_class extends  local_joulegrader_lib_pane_grade_abstract {

    protected $usergrades;

    /**
     * Do some initialization
     */
    public function init() {
        global $USER;

        $assignment = $this->gradingarea->get_assign();
        $usergrade = $this->get_usergrade($this->gradingarea->get_guserid());

        $this->courseid = $assignment->get_instance()->course;

        $this->gradinginfo = grade_get_grades($this->courseid, 'mod', 'assign', $assignment->get_instance()->id, array($this->gradingarea->get_guserid()));

        $this->gradingdisabled = $this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->locked;
        $this->gradeoverridden = $this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->overridden;

        if (($gradingmethod = $this->gradingarea->get_active_gradingmethod()) && in_array($gradingmethod, self::get_supportedplugins())) {
            $controller = $this->gradingarea->get_gradingmanager()->get_controller($gradingmethod);
            $this->controller = $controller;
            if ($controller->is_form_available()) {
                $itemid = null;
                if (!empty($usergrade->id)) {
                    $itemid = $usergrade->id;
                }
                if ($this->gradingdisabled && $itemid) {
                    $this->gradinginstance = $controller->get_current_instance($USER->id, $itemid);
                } else if (!$this->gradingdisabled) {
                    $instanceid = optional_param('gradinginstanceid', 0, PARAM_INT);
                    $this->gradinginstance = $controller->get_or_create_instance($instanceid, $USER->id, $itemid);
                }

                $currentinstance = null;
                if (!empty($this->gradinginstance)) {
                    $currentinstance = $this->gradinginstance->get_current_instance();
                }
                $this->needsupdate = false;
                if (!empty($currentinstance) && $currentinstance->get_status() == gradingform_instance::INSTANCE_STATUS_NEEDUPDATE) {
                    $this->needsupdate = true;
                }
            } else {
                $this->advancedgradingerror = $controller->form_unavailable_notification();
            }
        }

        $this->teachercap = has_capability($this->gradingarea->get_teachercapability(), $this->gradingarea->get_gradingmanager()->get_context());
    }

    /**
     * @return bool
     */
    public function has_grading() {
        $hasgrading = true;
        $assignment = $this->gradingarea->get_assign();

        if ($assignment->get_instance()->grade == 0) {
            $hasgrading = false;
        }

        return $hasgrading;
    }

    public function has_teachercap() {
        return $this->teachercap;
    }

    public function has_modal() {
        return !(empty($this->controller) || empty($this->gradinginstance) || (!empty($this->controller) && !$this->controller->is_form_available()));
    }

    public function get_needsupdate() {
        return $this->needsupdate;
    }

    public function get_courseid() {
        return $this->courseid;
    }

    public function get_grade() {
        $assignment = $this->gradingarea->get_assign();
        return $assignment->get_instance()->grade;
    }

    public function has_override() {
        return !$this->gradingdisabled && $this->gradeoverridden;
    }

    public function get_currentgrade() {
        $usergrade = $this->get_usergrade($this->gradingarea->get_guserid());

        $grade = -1;
        if (!empty($usergrade) && isset($usergrade->grade)) {
            $grade = $usergrade->grade;
        }

        return $grade;
    }

    public function has_paneform() {
        return (empty($this->controller) || empty($this->gradinginstance) || (!empty($this->controller) && !$this->controller->is_form_available()));
    }

    public function has_active_gradinginstances() {
        $usergrade = $this->get_usergrade($this->gradingarea->get_guserid());
        return !(empty($usergrade) || !$this->controller->get_active_instances($usergrade->id));
    }

    public function get_agitemid() {
        $agitem = null;
        $usergrade = $this->get_usergrade($this->gradingarea->get_guserid());

        if (!empty($usergrade) && isset($usergrade->id)) {
            $agitem = $usergrade->id;
        }
        return $agitem;
    }

    /**
     * @return bool
     */
    public function not_graded() {
        $notgraded = false;

        $assignment = $this->gradingarea->get_assign();
        $usergrade  = $this->get_usergrade($this->gradingarea->get_guserid());

        if ($assignment->get_instance()->grade != 0) {
            //check the submission first
            if (!empty($usergrade) && $usergrade->grade == -1) {
                $notgraded = true;
            } else if (!empty($this->gradinginfo) && is_null($this->gradinginfo->items[0]->grades[$this->gradingarea->get_guserid()]->grade)) {
                //check the gradebook
                $notgraded = true;
            }
        }

        return $notgraded;
    }

    /**
     * @param MoodleQuickForm $mform
     */
    public function paneform_hook($mform) {
        $this->add_applyall_element($mform);
        $this->blindmarking_modification($mform);
    }

    /**
     * @param MoodleQuickForm $mform
     */
    public function modalform_hook($mform) {
        $this->add_applyall_element($mform);
        $this->blindmarking_modification($mform);
    }

    /**
     * @param MoodleQuickForm $mform
     */
    private function blindmarking_modification($mform) {
        // Check to see if the assignment uses blind marking.
        if ($this->gradingarea->get_assign()->is_blind_marking()) {
            // Check to see if the override element has been added to the form.
            if ($mform->elementExists('override')) {
                $mform->removeElement('override');
            }
        }
    }

    /**
     * @param MoodleQuickForm $mform
     */
    private function add_applyall_element($mform) {
        $assignment = $this->gradingarea->get_assign();
        $teamsubmission = $assignment->get_instance()->teamsubmission;
        if (!empty($teamsubmission)) {
            $mform->addElement('select', 'applytoall', get_string('applytoall', 'local_joulegrader'), array(get_string('no'), get_string('yes')));
            $mform->setDefault('applytoall', 1);
            $mform->setType('applytoall', PARAM_BOOL);
        }
    }

    /**
     * @param $data
     * @param mr_notify $notify
     */
    public function process($data, $notify) {
        //a little setup
        $assignment = $this->gradingarea->get_assign();

        //set up a redirect url
        $redirecturl = new moodle_url('/local/joulegrader/view.php', array('courseid' => $this->courseid
                , 'garea' => $this->get_gradingarea()->get_areaid(), 'guser' => $this->get_gradingarea()->get_guserid()));

        //get the data from the form
        if ($data) {

            if ($assignment->get_instance()->grade < 0) {
                // Scale grade.
                $data->grade = clean_param($data->grade, PARAM_INT);
            } else if (!isset($data->gradinginstanceid)) {
                //just using regular grading
                $lettergrades = grade_get_letters(context_course::instance($this->courseid));
                $grade = $data->grade;

                // Determine if user is submitting as a letter grade, percentage or float.
                $touppergrade = textlib::strtoupper($grade);
                $toupperlettergrades = array_map('textlib::strtoupper', $lettergrades);
                if (in_array($touppergrade, $toupperlettergrades)) {
                    // Submitting lettergrade, find percent grade.
                    $percentvalue = 0;
                    $max = 100;
                    foreach ($toupperlettergrades as $value => $letter) {
                        if ($touppergrade == $letter) {
                            $percentvalue = ($max + $value) / 2;
                            break;
                        }
                        $max = $value - 1;
                    }

                    // Transform to an integer within the range of the assignment.
                    $data->grade = (int) ($assignment->get_instance()->grade * ($percentvalue / 100));

                } else if (strpos($grade, '%') !== false) {
                    // Trying to submit percentage.
                    $percentgrade = trim(strstr($grade, '%', true));
                    $percentgrade = clean_param($percentgrade, PARAM_FLOAT);

                    // Transform to an integer within the range of the assignment.
                    $data->grade = (int) ($assignment->get_instance()->grade * ($percentgrade / 100));

                } else if ($grade === '') {
                    // Setting to "No grade".
                    $data->grade = -1;
                } else {
                    // Just a numeric value, clean it as int b/c that's what assignment module accepts.
                    $data->grade = clean_param($grade, PARAM_INT);
                }
            }

            $userid = $this->get_gradingarea()->get_guserid();
            $teamsubmission = $assignment->get_instance()->teamsubmission;
            if (!empty($teamsubmission) && !empty($data->applytoall)) {
                $groupid = 0;
                if ($assignment->get_submission_group($userid)) {
                    $group = $assignment->get_submission_group($userid);
                    if ($group) {
                        $groupid = $group->id;
                    }
                }
                $members = $assignment->get_submission_group_members($groupid, true);
                foreach ($members as $member) {
                    // User may exist in multiple groups (which should put them in the default group).
                    $this->apply_grade_to_user($data, $member->id);
                }
            } else {
                $this->apply_grade_to_user($data, $userid);
            }


            //redirect to next user if set
            if (optional_param('saveandnext', 0, PARAM_BOOL) && !empty($data->nextuser)) {
                $redirecturl->param('guser', $data->nextuser);
            }

            if (optional_param('needsgrading', 0, PARAM_BOOL)) {
                $redirecturl->param('needsgrading', 1);
            }

//            //save the grade
//            if ($this->save_grade($grade, isset($data->override))) {
//                $notify->good('gradesaved');
//            }
        }

        redirect($redirecturl);
    }

    protected function apply_grade_to_user($data, $userid) {
        global $USER;

        $assignment = $this->gradingarea->get_assign();
        $usergrade = $this->get_usergrade($userid, true);

        $teamsubmission = $assignment->get_instance()->teamsubmission;

        if (isset($data->gradinginstanceid)) {
            $gradesmenu = make_grades_menu($assignment->get_instance()->grade);
            // Using advanced grading.
            if ($userid == $this->gradingarea->get_guserid()) {
                // Can use the grading instance already set up by this object.
                $gradinginstance = $this->gradinginstance;
            } else {
                $gradingdisabled = $assignment->grading_disabled($userid);
                $itemid = null;
                if ($usergrade) {
                    $itemid = $usergrade->id;
                }
                if ($gradingdisabled && $itemid) {
                    $gradinginstance = ($this->controller->get_current_instance($USER->id, $itemid));
                } else if (!$gradingdisabled) {
                    $instanceid = optional_param('gradinginstanceid', 0, PARAM_INT);
                    $gradinginstance = ($this->controller->get_or_create_instance($instanceid, $USER->id, $itemid));
                }
            }

            $this->controller->set_grade_range($gradesmenu);
            $usergrade->grade = $gradinginstance->submit_and_get_grade($data->grade, $usergrade->id);
        } else {
            // The grade has already been processed in the process method.
            $usergrade->grade = $data->grade;
        }

        $override = false;
        $blindmarking = $assignment->is_blind_marking();
        if (!$blindmarking) {
            $override = isset($data->override) || (!empty($teamsubmission) && !empty($data->applytoall));
        }

        $this->save_grade($usergrade, $override, $blindmarking);
    }

    /**
     * @param $usergrade
     * @param bool $override
     * @param bool $blindmarking
     *
     * @return bool
     */
    protected function save_grade($usergrade, $override, $blindmarking = false) {
        global $USER, $DB;

        // Need to do two things here.
        // 1) update the grade in assign_grades table.
        // 2) update the grade in the gradebook if the grade is NOT overridden
        //    or if it IS overridden AND the override check was checked.
        $assignment = $this->gradingarea->get_assign();

        $usergrade->grader = $USER->id;
        $usergrade->timemodified = time();

        // Update the submission.
        $success = $DB->update_record('assign_grades', $usergrade);

        // If blind marking is used don't upgrade the gradebook.
        if ($blindmarking)  {
            // Blind marking is being used and identities have not been revealed. Return here.
            return $success;
        }

        // Now need to update the gradebook.
        if ($usergrade->userid == $this->gradingarea->get_guserid()) {
            $gradinginfo = $this->gradinginfo;
        } else {
            $gradinginfo = grade_get_grades($this->get_courseid(), 'mod', 'assign', $assignment->get_instance()->id, array($usergrade->userid));
        }
        $gradeoverridden = $gradinginfo->items[0]->grades[$usergrade->userid]->overridden;
        if ($success && !$gradeoverridden) {
            $gradebookgrade = $this->convert_grade_for_gradebook($usergrade);

            $assign = clone $assignment->get_instance();
            $assign->cmidnumber = $assignment->get_course_module()->id;

            $success = $success && (GRADE_UPDATE_OK == assign_grade_item_update($assign, $gradebookgrade));
        } else if ($success && $override) {
            // Try to fetch the gradeitem first.
            $params = array('courseid' => $this->courseid, 'itemtype' => 'mod'
            , 'itemmodule' => 'assign', 'iteminstance' => $assignment->get_instance()->id);

            $gradeitem = grade_item::fetch($params);

            // If no grade item, create a new one.
            if (empty($gradeitem)) {

                $params['itemname'] = $assignment->get_instance()->name;
                $params['idnumber'] = $assignment->get_course_module()->id;

                // Set up additional params for the grade item.
                if ($assignment->get_instance()->grade > 0) {
                    $params['gradetype'] = GRADE_TYPE_VALUE;
                    $params['grademax']  = $assignment->get_instance()->grade;
                    $params['grademin']  = 0;

                } else if ($assignment->assignment->grade < 0) {
                    $params['gradetype'] = GRADE_TYPE_SCALE;
                    $params['scaleid']   = -$assignment->get_instance()->grade;

                } else {
                    $params['gradetype'] = GRADE_TYPE_TEXT; // allow text comments only
                }

                // Create and insert the new grade item.
                $gradeitem = new grade_item($params);
                $gradeitem->insert();
            }

            // If grade is -1 in assign_grades table, it should be passed as null.
            $grade = $usergrade->grade;
            if ($grade == -1) {
                $grade = null;
            }

            $success = $success && (bool) $gradeitem->update_final_grade($usergrade->userid, $grade, 'local/joulegrader', false
                    , FORMAT_MOODLE, $usergrade->grader);
        }

        return $success;
    }

    /**
     * @param int $userid
     * @param bool $create
     * @return bool|stdClass
     */
    private function get_usergrade($userid, $create = false) {
        if (empty($this->usergrades[$userid])) {
            $this->usergrades[$userid] = $this->gradingarea->get_assign()->get_user_grade($userid, $create);
        }

        return $this->usergrades[$userid];
    }

    /**
     * convert the final raw grade(s) in the  grading table for the gradebook
     * (Taken from assign::convert_grade_for_gradebook, which is a private method
     *
     * @param stdClass $grade
     * @return array
     */
    private function convert_grade_for_gradebook(stdClass $grade) {
        $gradebookgrade = array();
        // trying to match those array keys in grade update function in gradelib.php
        // with keys in th database table assign_grades
        // starting around line 262
        if ($grade->grade >= 0) {
            $gradebookgrade['rawgrade'] = $grade->grade;
        }
        $gradebookgrade['userid'] = $grade->userid;
        $gradebookgrade['usermodified'] = $grade->grader;
        $gradebookgrade['datesubmitted'] = NULL;
        $gradebookgrade['dategraded'] = $grade->timemodified;
        if (isset($grade->feedbackformat)) {
            $gradebookgrade['feedbackformat'] = $grade->feedbackformat;
        }
        if (isset($grade->feedbacktext)) {
            $gradebookgrade['feedback'] = $grade->feedbacktext;
        }

        return $gradebookgrade;
    }
}
