<?php
/**
 * Focused Form Display Logic
 * View and edit Form Display Logic rules using a focussed context: 
 * - by Instrument in the Online Designer table of instruments
 * - by Event and Instrument on the Designate Instruments to My Events page</li><ul>
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\FocusedFDL;

use ExternalModules\AbstractExternalModule;

class FocusedFDL extends AbstractExternalModule
{
    protected $arm;
    protected $arm_events_forms;

    public function redcap_every_page_top($project_id) {
        if (empty($project_id)) return;
        if (!defined('PAGE')) return;
        if (PAGE=='Design/online_designer.php' && !isset($_GET['page'])) {
            $this->online_designer_page_top($project_id);
        } else if (PAGE=='Design/designate_forms.php') {
            $this->designate_forms_page_top($project_id);
        }
    }

    protected function online_designer_page_top($project_id) {
        global $Proj;
        $surveyColOffset = intval($Proj->project['surveys_enabled']);
        $fdl_conditions = \FormDisplayLogic::getFormDisplayLogicTableValues($project_id);
        $form_fdl = array();
        foreach (array_keys($Proj->forms) as $form) {
            $form_fdl[$form] = array();
        }
        foreach ($fdl_conditions['controls'] as $i => $control) {
            foreach ($control['form-name'] as $frmevt) {
                list($f,$e) = explode('-', $frmevt, 2);
                if (!in_array($control['control_id'], $form_fdl[$f])) $form_fdl[$f][] = $control['control_id'];
            }
        }

        $this->initializeJavascriptModuleObject();
        ?>
        <style type="text/css">
            .importantHide { display:none !important; }
        </style>
        <script type="text/javascript">
            // Designate Form Display Logic - FDL buttons for each instrument
            $(function(){
                var module = <?=$this->getJavascriptModuleObjectName()?>;
                module.tt_add('fdl','<?=\RCView::tt_js('design_985')?>'); // Form Display Logic
                module.tt_add('view','<?=\RCView::tt_js('global_84')?>'); // View
                module.current_control_count = <?=count($fdl_conditions['controls'])?>;
                module.current_form_conditions = [];
                module.form_controls = <?=\json_encode_rc($form_fdl)?>;
                module.form_tooltip = '<a tabindex="0" class="DesignateFDL_view ml-1 fs10" role="button" data-bs-toggle="tooltip" data-bs-placement="right"><i class="fas fa-eye-slash"></i></a>'
                module.actionColOffset = 5+<?=$surveyColOffset?>;
                module.insertFDLButton = function() {
                    let actionDD = $(this);
                    let clickCodeMatch = $(actionDD).attr('onclick').match(/^saveFormODrow\(\'([a-z0-9_]+)\'/);
                    let form_name = clickCodeMatch[1];
                    let form_conditions = module.form_controls.hasOwnProperty(form_name)
                        ? module.form_controls[form_name] : [];
                    
                    let col = (form_conditions.length) ? '#444' : '#ccc';

                    let btn = $(module.form_tooltip);
                    btn.data('form_name', form_name);
                    btn.data('form_conditions', form_conditions);
                    btn.on('click', module.launchFDL)
                        .css('color', col)
                        .insertAfter(this);
                };
                module.tooltipContent = function(el) {
                    let form_name = $(el).data('form_name');
                    let form_conditions = $(el).data('form_conditions');
                    let conditionText = '<b>'+form_conditions.length+'</b> condition'+((form_conditions.length===1)?'':'s');
                    let content = '<i class="fas fa-eye-slash"></i> '+module.tt('fdl'); // Form Display Logic
                    content += '<br>'+conditionText;
                    return content;
                }
                module.launchFDL = function(evt) {
                    $(".tooltip").tooltip("hide");
                    module.current_form_conditions = $(this).data('form_conditions');
                    displayFormDisplayLogicPopup(module.current_form_conditions);
                };
                module.fdlDialogOpen = function() {
                    if (Array.isArray(module.current_form_conditions)) { // launched from form-specific button, not main button?
                        // console.log(module.current_form_conditions);
                        $('#FormDisplayLogicSetupDialog').find('div:first').hide()
                        $('#FormDisplayLogicSetupDialog div.data').hide();
                        $('#deleteAll').addClass('importantHide');
                        // hide all of the control conditions that are not applicable to the current selected event/form
                        if (module.current_control_count > 0) {
                            $('input[id^=control_id]').each(function(){
                                let controlVal = $(this).val();
                                if (!module.current_form_conditions.includes(controlVal)) {
                                    $(this).closest('div.repeater-divs').hide();
                                }
                            });
                        }
                    } else {
                        $('#FormDisplayLogicSetupDialog').find('div:first').show()
                        $('#FormDisplayLogicSetupDialog div.data').show();
                        $('#deleteAll').removeClass('importantHide');
                    }
                };
                module.init = function() {
                    //console.log(module.form_controls);
                    // steal a bit of the form name column to widen the form actions column enough for the FDL buttons
                    let odTableRows = $('#forms_surveys div.hDiv,#forms_surveys div.bDiv').find('tr');
                    let formlabeldivs = $(odTableRows).find('th:nth-of-type(2) div:first, td:nth-of-type(2) div:first');
                    let formlabeldivWidth = $(formlabeldivs).eq(0).width();
                    $(formlabeldivs).width(formlabeldivWidth-12);
                    let formactiondivs = $(odTableRows).find('th:nth-of-type('+module.actionColOffset+') div:first, td:nth-of-type('+module.actionColOffset+') div:first');
                    let formactiondivWidth = $(formactiondivs).eq(0).width();
                    $(formactiondivs).width(formactiondivWidth+12);

                    $('button.formActionDropdownTrigger').each(module.insertFDLButton);
                    const tooltipTriggerList = $('a.DesignateFDL_view[data-bs-toggle="tooltip"]');
                    const tooltipList = [...tooltipTriggerList].map(triggerEl => new bootstrap.Tooltip(triggerEl, {
                        html: true,
                        sanitize: false,
                        title: module.tooltipContent(triggerEl)
                    }));
                    $('body').on('dialogopen', function(event){
                        if(event.target.id=='FormDisplayLogicSetupDialog') {
                            module.fdlDialogOpen();
                        }
                    });

                    // override default fdl dialog launch function so we can tell whether it is being launched by the main button or a form-specific one
                    module.displayFormDisplayLogicPopup = displayFormDisplayLogicPopup;
                    displayFormDisplayLogicPopup = function(conditions=null) {
                        if (conditions==null) {
                            module.current_form_conditions = null;
                        }
                        module.displayFormDisplayLogicPopup();
                    }
                };

                $(document).ready(function(){ module.init(); });
            });
        </script>
        <?php
    }

    protected function designate_forms_page_top($project_id) {
        global $Proj;

        $event_unique_names = \REDCap::getEventNames(true, false);

        $fdl_conditions = \FormDisplayLogic::getFormDisplayLogicTableValues($project_id);

        $this->arm = (isset($_GET['arm_id']) && intval($_GET['arm_id'])>0) ? intval($_GET['arm_id']) : 1;
        $this->arm_events_forms = $Proj->getInstrEventMapRecords(['arms'=>$this->arm]);

        // get this arm's events and forms, and the FDL conditions pertaining to each event/form
        $arm_events = $Proj->getEventsByArmNum($this->arm);
        foreach ($arm_events as $event_id) {
            $event_unique_name = $event_unique_names[$event_id];
            foreach ($this->arm_events_forms as $i => $event_form) {
                if ($event_form['unique_event_name']==$event_unique_name) {
                    $this->arm_events_forms[$i]['event_id'] = $event_id;
                }
            }
        }

        // iterate the fdl control conditions and where relevant add to form/event 
        foreach ($this->arm_events_forms as $i => $event_form) {
            $this->arm_events_forms[$i]['conditions'] = array();
            foreach ($fdl_conditions['controls'] as $control) {
                foreach ($control['form-name'] as $form_dash_event) {
                    list($control_form,$control_event) = explode('-', $form_dash_event);
                    $this_event = $event_form['event_id'];
                    $this_form = $event_form['form'];

                    // rule applies to form either if not event specific or event matches 
                    if ($control_form==$this_form && ($control_event=='' || $control_event==$this_event) ) {
                        $this->arm_events_forms[$i]['conditions'][] = $control;
                    }
                }
            }
        }

        ?>
        <script type="text/javascript">disable_instrument_table = true; // Designate Form Display Logic: initialisation required for displayFormDisplayLogicPopup()</script><?php
        loadJS('DesignForms.js');
        $this->initializeJavascriptModuleObject();
        ?>
        <style type="text/css">
            #deleteAll { display:none !important; }
        </style>
        <script type="text/javascript">
            // Designate Form Display Logic
            $(function(){
                lang.designate_forms_13 = '<?=\RCView::tt_js('designate_forms_13')?>';
                langErrorColon = '<?=\RCView::tt_js('global_01').\RCView::tt_js('colon')?>';
                form_missing = '<?=\RCView::tt_js('design_988') ?>';
                logic_missing = '<?=\RCView::tt_js('design_989') ?>';
                duplicate_warning = '<?=\RCView::tt_js('design_971') ?>';
                confirm_msg = '<?=\RCView::tt_js('design_972') ?>';
                langFDL1 = '<?=\RCView::tt_js('design_993') ?>';
                langFDL2 = '<?=\RCView::tt_js('design_994') ?>';
                langFDL3 = '<?=\RCView::tt_js('design_995') ?>';
                langFDL4 = '<?=\RCView::tt_js('design_1093') ?>';
                delete_conditions = function() { return false; }

                var module = <?=$this->getJavascriptModuleObjectName()?>;
                module.tt_add('fdl','<?=\RCView::tt_js('design_985')?>'); // Form Display Logic
                module.tt_add('view','<?=\RCView::tt_js('global_84')?>'); // View
                module.current_control_count = <?=count($fdl_conditions['controls'])?>;
                module.event_unique_names = <?=\json_encode_rc($event_unique_names)?>;
                module.arm_events_forms = <?=\json_encode_rc($this->arm_events_forms)?>;
                module.event_form_conditions = [];
                module.form_event_tooltip = '<a tabindex="0" class="DesignateFDL_view ml-1 fs10" role="button" data-bs-toggle="tooltip" data-bs-placement="bottom"><i class="fas fa-eye-slash"></i></a>'
                module.getTickFormEvent = function(img) {
                    let id = $(img).attr('id'); // e.g. 'img--screening_form--3210
                    let td = $(img).parents('td:first');
                    let i = $(td).index();
                    let th = $('#event_grid_table th').eq(i);
                    let idparts = id.split('--');
                    let form_name = idparts[1];
                    let event_id = idparts[2];
                    let form_label = $(td).parent('tr').find('td:first').text();
                    let event_label = $(th).find('div:first').text();
                    return {
                        form_name: form_name,
                        form_label: form_label,
                        event_id: event_id,
                        event_name: module.event_unique_names[event_id],
                        event_label: event_label
                    };
                };
                module.getTickProperty = function(img, prop) {
                    let tick = module.getTickFormEvent(img);
                    return (tick.hasOwnProperty(prop)) ? tick[prop] : null;
                };
                module.getConditions = function(event_name, form_name){
                    let conditions = [];
                    module.arm_events_forms.forEach(function(e){
                        if (e.unique_event_name == event_name && e.form == form_name && e.conditions.length) {
                            e.conditions.forEach(function(c){
                                conditions.push(c.control_id);
                            });
                        }
                    });
                    return conditions;
                };
                module.insertFDLButton = function() {
                    let tick = $(this);
                    let event_name = module.getTickProperty(tick, 'event_name');
                    let form_name = module.getTickProperty(tick, 'form_name');
                    let conditions = module.getConditions(event_name, form_name);
                    
                    let col = (conditions.length) ? '#444' : '#ccc';

                    let btn = $(module.form_event_tooltip);
                    btn.on('click', conditions, module.launchFDL)
                        .css('color', col)
                        .insertAfter(this);
                };
                module.tooltipContent = function(el) {
                    let tick = $(el).siblings('img:first');
                    let event_name = module.getTickProperty(tick, 'event_name');
                    let form_name = module.getTickProperty(tick, 'form_name');
                    
                    let conditions = module.getConditions(event_name, form_name);
                    let conditionText = '<b>'+conditions.length+'</b> condition'+((conditions.length===1)?'':'s');

                    let content = '<i class="fas fa-eye-slash"></i> '+module.tt('fdl'); // Form Display Logic
                    content += '<br>'+module.getTickProperty(tick, 'event_label');
                    content += '<br>'+module.getTickProperty(tick, 'form_label');
                    content += '<br>'+conditionText;
                    return content;
                }
                module.launchFDL = function(evt) {
                    $(".tooltip").tooltip("hide");
                    module.event_form_conditions = evt.data;
                    displayFormDisplayLogicPopup();
                };
                module.fdlDialogOpen = function() {
                    // console.log(module.event_form_conditions);
                    $('#FormDisplayLogicSetupDialog').find('div:first').hide()
                    $('#FormDisplayLogicSetupDialog div.data').hide();
                    $('button#deleteAll').hide();
                    // hide all of the control conditions that are not applicable to the current selected event/form
                    if (module.current_control_count > 0) {
                        $('input[id^=control_id]').each(function(){
                            let controlVal = $(this).val();
                            if (!module.event_form_conditions.includes(controlVal)) {
                                $(this).closest('div.repeater-divs').hide();
                            }
                        });
                    }
                };
                module.init = function() {
                    //console.log(module.arm_events_forms);
                    $('#event_grid_table img').each(module.insertFDLButton);
                    const tooltipTriggerList = $('a[data-bs-toggle="tooltip"]');
                    const tooltipList = [...tooltipTriggerList].map(triggerEl => new bootstrap.Tooltip(triggerEl, {
                        html: true,
                        sanitize: false,
                        title: module.tooltipContent(triggerEl)
                    }));
                    $('body').on('dialogopen', function(event){
                        if(event.target.id=='FormDisplayLogicSetupDialog') {
                            module.fdlDialogOpen();
                        }
                    });
                };

                $(document).ready(function(){ module.init(); });
            });
        </script>
        <?php
    }
}