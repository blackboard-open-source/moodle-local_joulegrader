<?php
defined('MOODLE_INTERNAL') or die('Direct access to this script is forbidden.');
require_once($CFG->libdir.'/formslib.php');
/**
 * Grade form for mod_hsuforum_posts
 *
 * @author Mark Nielsen
 * @package local/joulegrader
 * @see moodleform
 */
class local_joulegrader_form_mod_hsuforum_posts_grade extends moodleform {

    public function definition() {
        $mform =& $this->_form;

        if (!empty($this->_customdata->cm->id)) {
            $mform->addElement('hidden', 'cmid', $this->_customdata->cm->id);
            $mform->setType('cmid', PARAM_INT);
        }

        $mform->addElement('hidden', 'nextuser', $this->_customdata->nextuser);
        $mform->setType('nextuser', PARAM_INT);

        $mform->addElement('hidden', 'forum', $this->_customdata->forum->id);

        //for the grade range
        $grademenu = make_grades_menu($this->_customdata->forum->scale);
        if (!empty($this->_customdata->gradinginstance)) {

            //set up the grading instance
            $gradinginstance = $this->_customdata->gradinginstance;
            $gradinginstance->get_controller()->set_grade_range($grademenu);
            $gradingelement = $mform->addElement('grading', 'grade', get_string('grade').':', array('gradinginstance' => $gradinginstance));
            if ($this->_customdata->gradingdisabled) {
                $gradingelement->freeze();
            } else {
                $mform->addElement('hidden', 'gradinginstanceid', $gradinginstance->get_id());
            }
        } else {
            //check for an existing grade
            $grade = -1;
            if (isset($this->_customdata->grade)) {
                $grade = $this->_customdata->grade;
            }
            //check to see if this is a scale
            $isscale = (bool) ($this->_customdata->forum->scale < 0);
            if ($isscale) {
                $grade = (int) $grade;
                $grademenu[-1] = get_string('nograde');
                //heading
                $mform->addElement('static', 'gradeheader', null, get_string('grade'));

                //scale grade element
                $mform->addElement('select', 'grade', null, $grademenu);
                $mform->setType('grade', PARAM_INT);
            } else {
                //add heading
                $mform->addElement('static', 'gradeheader', null, get_string('gradeoutof', 'local_joulegrader', $this->_customdata->forum->scale));

                //add the grade text element
                $mform->addElement('text', 'grade', null, array('size' => 5));

                //want to accept numbers, letters, percentage here
                $mform->setType('grade', PARAM_RAW_TRIMMED);

                //if the there is no grade yet make it blank
                if ($grade == -1) {
                    $grade = '';
                } else {
                    $grade = format_float($grade, 2);
                }
            }

            $mform->setDefault('grade', $grade);
        }

        //check for override
        if ($this->_customdata->gradeoverridden) {
            //if overridden in gradebook, add a checkbox
            //$mform->addElement('checkbox', 'override', null, get_string('overridetext', 'local_joulegrader'));
        }

        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('submit', 'submit', get_string('save', 'local_joulegrader'));
        if (isset($this->_customdata->nextuser)) {
            $buttonarray[] = &$mform->createElement('submit', 'saveandnext', get_string('saveandnext', 'local_joulegrader'));
        }

        $mform->addGroup($buttonarray, 'grading_buttonar', '', array(' '), false);
        $mform->setType('grading_buttonar', PARAM_RAW);
    }

    /**
     * Form validation
     *
     * @param $data
     * @param $files
     *
     * @return bool
     */
    public function validation($data, $files) {
        $validated = true;

        $modulegrade = $this->_customdata->forum->scale;

        //only need to do extra validation if they submitted via text box (not an advanced grading and not scale)
        if (!isset($this->_customdata->gradinginstance) && $modulegrade  >= 0) {
            $validated = array('grade' => get_string('gradeoutofrange', 'local_joulegrader'));

            //just using regular grading
            $lettergrades = grade_get_letters(context_course::instance($this->_customdata->cm->course));
            $grade = trim($data['grade']);

            //determine if user is submitting as a letter grade, percentage or float
            if ($grade === '') {
                $validated = true;
            } else if (is_numeric($grade)) {
                //straight point value
                $grade = clean_param($grade, PARAM_INT);

                //needs to be in range 0 - $assignmentgrade
                if ($grade >= 0 && $grade <= $modulegrade) {
                    $validated = true;
                }
            } else if (strpos($grade, '%') !== false) {
                //trying to submit percentage
                $percentgrade = trim(strstr($grade, '%', true));

                // make sure what is left is numeric
                if (is_numeric($percentgrade)) {
                    $percentgrade = clean_param($percentgrade, PARAM_INT);
                    if ($percentgrade >= 0 && $percentgrade <= 100) {
                        $validated = true;
                    }
                }
            } else if (in_array(textlib::strtoupper($grade), array_map('textlib::strtoupper', $lettergrades))) {
                //look for a lettergrade
                $validated = true;
            }
        }

        return $validated;
    }
}