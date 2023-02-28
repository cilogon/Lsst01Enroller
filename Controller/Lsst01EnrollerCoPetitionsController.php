<?php

// This COmanage Registry enrollment plugin is intended to be used
// with a self-signup enrollment flow for LSST that
// automatically adds users to a CO Group. It forces the
// CO group object to be provisioned when the enrollment flow
// completes.
//
// The following enrollment steps are implemented:
//
// petitionerAttributes:
//   - promotes the Name of type preferred to be the
//     primary name
// provision:
//   - invokes manualProvision() on the CO Group(s)
// start:
//   - detects duplicate enrollments and redirects
//     as appropriate

App::uses('CoPetitionsController', 'Controller');
App::uses('HtmlHelper', 'View/Helper');
 
class Lsst01EnrollerCoPetitionsController extends CoPetitionsController {
  // Class name, used by Cake
  public $name = "Lsst01EnrollerCoPetitions";
  public $uses = array(
    "CoPetition",
    "Lsst01Enroller.Lsst01Enroller"
  );

  /**
   * Plugin functionality following petitionerAttributes step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */

  protected function execute_plugin_petitionerAttributes($id, $onFinish) {
    // Pull the petition and its related models.
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['EnrolleeCoPerson'] = 'Name';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Petitioner Attributes: Petition is " . print_r($petition, true));

    // Loop over the Name objects to find the Name of type preferred
    // and set it to be the primary name. Note that the beforeSave()
    // method on the Name model will take care of updating the other
    // Name objects as necessary.
    if(!empty($petition['EnrolleeCoPerson']['Name'])) {
      if(is_array($petition['EnrolleeCoPerson']['Name'])) {
        foreach($petition['EnrolleeCoPerson']['Name'] as $name) {
          if($name['type'] == NameEnum::Preferred) {
            $id = $name['id'];
            $data = array();
            $data['Name'] = $name;
            $data['Name']['primary_name'] = true;

            if($this->CoPetition->EnrolleeCoPerson->Name->save($data)) {
              $this->log("Set primary_name true for Name with ID $id");
            } else {
              $this->log("ERROR: could not set primary_name true for Name with ID $id");
            }
            break;
          }
        }
      }
    }

    $this->redirect($onFinish);
  }

  /**
   * Plugin functionality following provision step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
   
  protected function execute_plugin_provision($id, $onFinish) {
    // Pull the petition and its related enrollment flow configuration.
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain']['CoEnrollmentFlow']['CoEnrollmentAttribute'] = 'CoEnrollmentAttributeDefault';

    $petition = $this->CoPetition->find('first', $args);
    $this->log("Provision: Petition is " . print_r($petition, true));

    // Examine the enrollment flow configuration to determine which, if any,
    // CO Groups should be re-provisioned.
    $groupIds = array();

    if(!empty($petition['CoEnrollmentFlow']['CoEnrollmentAttribute'])) {
      foreach($petition['CoEnrollmentFlow']['CoEnrollmentAttribute'] as $attribute) {
        if($attribute['attribute'] == 'g:co_group_member') {
          if(!empty($attribute['CoEnrollmentAttributeDefault'][0]['value'])) {
            $groupId = $attribute['CoEnrollmentAttributeDefault'][0]['value'];
            $groupIds[] = $groupId;
          }
        }
      }
    }

    $this->log("Provision: will force provisioning of CO Groups with IDs " . print_r($groupIds, true));

    // Invoke a manual re-provision on the CO Group.
    foreach($groupIds as $groupId) {
      $this->CoPetition->Co->CoGroup->manualProvision(null, null, $groupId);
      $this->log("Provision: reprovsioned CO Group with ID $groupId");
    }

    $this->redirect($onFinish);
  }

  /**
   * Plugin functionality following start step
   *
   * @param Integer $id CO Petition ID
   * @param Array $onFinish URL, in Cake format
   */
   
  protected function execute_plugin_start($id, $onFinish) {
    // This plugin assumes the authentication flow requires 
    // authentication and so at this point we can examine
    // the session to find the authenticated user's login identifiers
    // and see if this user already has a petition in process
    // or is already enrolled.

    // Grab the authenticated identifier.
    $auth_identifier = $this->Session->read('Auth.User.username');
    if(!isset($auth_identifier)) {
      $msg = "ERROR: could not find authenticated identifier";
      $this->log($msg);
      $this->Flash->set($msg, array('key' => 'error'));
      $this->redirect("/");
    }

    $this->log("Start: authenticated identifier is $auth_identifier");

    // Pull any existing Identifiers with this value and
    // the related models.
    $args = array();
    $args['conditions']['Identifier.identifier'] = $auth_identifier;
    $args['conditions']['Identifier.login'] = true;
    $args['conditions']['Identifier.status'] = SuspendableStatusEnum::Active;
    $args['contain']['OrgIdentity']['CoPetition']['EnrolleeCoPerson'] = array();
    $args['contain']['OrgIdentity']['CoPetition']['EnrolleeCoPerson'][] = 'Name';
    $args['contain']['OrgIdentity']['CoPetition']['EnrolleeCoPerson'][] = 'EmailAddress';
    $args['contain']['OrgIdentity']['CoPetition']['EnrolleeCoPerson'][] = 'Identifier';

    $identifiers = $this->CoPetition->Co->OrgIdentity->Identifier->find('all', $args);
    $this->log("Start: found existing Identifier objects " . print_r($identifiers, true));

    // If there are no Identifier objects found then let the enrollment
    // flow proceed normally.
    if(empty($identifiers)) {
      $this->redirect($onFinish);
    }

    // Pull our plugin/enrollment flow wedge configuration.
    $efwid = $this->viewVars['vv_efwid'];

    $args = array();
    $args['conditions']['Lsst01Enroller.co_enrollment_flow_wedge_id'] = $efwid;
    $args['contain']['CoEnrollmentFlowWedge']['CoEnrollmentFlow'] = 'CoEnrollmentFlowWedge';
    
    $cfg = $this->Lsst01Enroller->find('first', $args);
    $this->log("Start: plugin configuration is " . print_r($cfg, true));

    // Loop over the found Identifier objects and detect existing
    // petitions and records.
    foreach($identifiers as $identifier) {
      if(!empty($identifier['OrgIdentity']['CoPetition'][0]['EnrolleeCoPerson'])) {
        $coPerson = $identifier['OrgIdentity']['CoPetition'][0]['EnrolleeCoPerson'];
        $status = $coPerson['status'];

        // Determine state of associated Name and Email address objects.
        $preferredEmail = false;
        $preferredName = false;

        if(!empty($coPerson['EmailAddress'])) {
          $emails = $coPerson['EmailAddress'];
          foreach($emails as $email) {
            if($email['type'] == EmailAddressEnum::Preferred) {
              $preferredEmail = true;
            }
          }
        }

        if(!empty($coPerson['Name'])) {
          $names = $coPerson['Name'];
          foreach($names as $name) {
            if($name['type'] == NameEnum::Preferred) {
              $preferredName = true;
            }
          }
        }

        // Pending Approval status means that the user has already enrolled
        // and is attempting to enroll again while waiting for the previous
        // petition to be approved.
        if($status == StatusEnum::PendingApproval) {
          if($preferredEmail && $preferredName) {
            // Redirect to page telling user to wait.
            $this->redirect($cfg['Lsst01Enroller']['pending_approval_redirect']);
          }
        }

        // Pending Confirmation status means that the user has already
        // enrolled and is attempting to enroll again. If the CoPerson
        // does not have a preferred name nor email address then just
        // redirect into the previous aborted enrollment flow. Else
        // the user is probably having problems receiving the email
        // confirmation so redirect to help page.
        if($status == StatusEnum::PendingConfirmation) {
          if(!$preferredEmail && !$preferredName) {
            $petition = $identifier['OrgIdentity']['CoPetition'][0];
            $petitionerToken = $petition['petitioner_token'];
            $petitionId = $petition['id'];

            $url = array();
            $url['plugin'] = null;
            $url['controller'] = 'co_petitions';
            $url['action'] = 'petitionerAttributes';
            $url[] = $petitionId;
            $url['token'] = $petitionerToken;

            $this->redirect($url);
          } else {
            $this->redirect($cfg['Lsst01Enroller']['pending_confirm_redirect']);
          }
        }

        // Confirmed status means the user has already confirmed their email
        // address and was probably presented with the form to collect a username
        // but then the flow stopped for some reason. Check to see if there is
        // no UID attached to the CO Person record and if not redirect to
        // the plugin so they can set it.
        if($status == StatusEnum::Confirmed) {
          $uidExists = false;
          foreach($coPerson['Identifier'] as $coPersonIdentifier) {
            if($coPersonIdentifier['type'] == IdentifierEnum::UID) {
              $uidExists = true;
              break;
            }
          }

          if(!$uidExists) {
            $petition = $identifier['OrgIdentity']['CoPetition'][0];
            $enrolleeToken = $petition['enrollee_token'];
            $petitionId = $petition['id'];

            // We need to direct into the IdentifierEnroller plugin, not
            // this plugin, so we need to find the enrollment flow wedge
            // ID for the IdentifierEnroller plugin.
            $identifierEnrollerEfwid = null;
            if(!empty($cfg['CoEnrollmentFlowWedge']['CoEnrollmentFlow']['CoEnrollmentFlowWedge'])) {
              $wedges = $cfg['CoEnrollmentFlowWedge']['CoEnrollmentFlow']['CoEnrollmentFlowWedge'];
              foreach($wedges as $wedge) {
                if($wedge['plugin'] == 'IdentifierEnroller') {
                  $identifierEnrollerEfwid = $wedge['id'];
                  break;
                }
              }
            }

            if($identifierEnrollerEfwid) {
              $url = array();
              $url['plugin'] = 'identifier_enroller';
              $url['controller'] = 'identifier_enroller_co_petitions';
              $url['action'] = 'collectIdentifier';
              $url[] = $petitionId;
              $url['efwid'] = $identifierEnrollerEfwid;
              $url['token'] = $enrolleeToken;

              $this->redirect($url);
            }
          }
        }
      }
    }

    // All Identifier objects have been examined and we have not found
    // any duplicates in expected states. The Identifier objects are most
    // likely associated with expunged/deleted OrgIdentity and CoPerson
    // records. So let the enrollment flow continue.
    $this->redirect($onFinish);
  }
}
