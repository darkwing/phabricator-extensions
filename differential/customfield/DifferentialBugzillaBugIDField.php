<?php
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

/**
 * Extends Differential with a 'Bugzilla Bug ID' field.
 */
final class DifferentialBugzillaBugIDField
  extends DifferentialStoredCustomField {

/* -(  Core Properties and Field Identity  )--------------------------------- */

  public function getFieldKey() {
    return 'differential:bugzilla-bug-id';
  }

  public function getFieldName() {
    return pht('Bugzilla Bug ID');
  }

  public function getFieldKeyForConduit() {
    // Link to DifferentialBugzillaBugIDCommitMessageField
    return 'bugzilla.bug-id';
  }

  public function getFieldDescription() {
    // Rendered in 'Config > Differential > differential.fields'
    return pht('Displays associated Bugzilla Bug ID.');
  }

  public function isFieldEnabled() {
    return true;
  }

  public function canDisableField() {
    // Field can't be switched off in configuration
    return false;
  }

/* -(  ApplicationTransactions  )-------------------------------------------- */

  public function shouldAppearInApplicationTransactions() {
    // Required to be editable
    return true;
  }

/* -(  Edit View  )---------------------------------------------------------- */

  public function shouldAppearInEditView() {
    // Should the field appear in Edit Revision feature
    // If set to false value will not be read from Arcanist commit message.
    // ERR-CONDUIT-CORE: Transaction with key "6" has invalid type
    // "bugzilla.bug-id". This type is not recognized. Valid types are: update,
    // [...]
    return true;
  }

  public function readValueFromRequest(AphrontRequest $request) {
    $this->setValue($request->getStr($this->getFieldKey()));
  }

  public function renderEditControl(array $handles) {
    return id(new AphrontFormTextControl())
      ->setLabel($this->getFieldName())
      ->setCaption(
        pht('Example: %s', phutil_tag('tt', array(), '2345')))
      ->setName($this->getFieldKey())
      ->setValue($this->getValue(), '');
  }

  public function validateApplicationTransactions(
    PhabricatorApplicationTransactionEditor $editor,
    $type, array $xactions) {

    $errors = parent::validateApplicationTransactions($editor, $type, $xactions);

    foreach ($xactions as $xaction) {
      $bug_id = $xaction->getNewValue();

      // Get the transactor's ExternalAccount->accountID using the author's phid
      $xaction_author_phid = $xaction->getAuthorPHID();
      $users = id(new PhabricatorExternalAccountQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withAccountTypes(PhabricatorBMOAuthProvider::ADAPTER_TYPE)
        ->withPHIDs($xaction_author_phid)
        ->execute();

      // In theory this should be impossible, unless someone is removed from
      // the system manually (somehow)
      if(!count($users)) {
        $errors[] = new PhabricatorApplicationTransactionValidationError(
          $type,
          pht(''),
          pht('This transaction\'s user was not found')
        );
        return $errors;
      }
      $user_id = $users[0]->getAccountID();

      // Required
      if(!strlen($bug_id)) {
        $errors[] = new PhabricatorApplicationTransactionValidationError(
          $type,
          pht('Required'),
          pht('Bugzilla Bug ID is required')
        );
      }
      else if (!ctype_digit($bug_id)) { // Isn't a number we can work with
        $errors[] = new PhabricatorApplicationTransactionValidationError(
          $type,
          pht('Required'),
          pht('Bugzilla Bug ID must be a number')
        );
      }
      else { // Make a request to BMO to ensure the bug exists and user can see it
        $future_uri = id(new PhutilURI(PhabricatorEnv::getEnvConfig('bugzilla.url')))
          ->setPath('/phabbugz/permissions/'.$bug_id.'/'.$user_id);

        // http://bugzilla.readthedocs.io/en/latest/api/core/v1/bug.html#get-bug
        // 100 (Invalid Bug Alias) If you specified an alias and there is no bug with that alias.
        // 101 (Invalid Bug ID) The bug_id you specified doesn't exist in the database.
        // 102 (Access Denied) You do not have access to the bug_id you specified.
        $accepted_status_codes = array(100, 101, 102, 200, 404);
        $future = id(new HTTPSFuture((string) $future_uri))
          ->setMethod('GET')
          ->addHeader('X-Bugzilla-API-Key', PhabricatorEnv::getEnvConfig('bugzilla.automation_api_key'))
          ->addHeader('Accept', 'application/json')
          ->setExpectStatus($accepted_status_codes)
          ->setTimeout(5);

        // Resolve the async HTTPSFuture request and extract JSON body
        try {
          // https://github.com/phacility/libphutil/blob/master/src/future/http/BaseHTTPFuture.php#L339
          list($status) = $future->parseRawHTTPResponse();
          $status_code = $status->getStatusCode();

          if(in_array($status_code, array(100, 101, 404))) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht(''),
              pht('Bugzilla Bug ID does not exist.')
            );
          }
          else if($status_code === 102) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht(''),
              pht('Bugzilla Bug ID: you do not have permission for this bug.')
            );
          }
          else if($status_code !== in_array($status_code, $accepted_status_codes)) {
            $errors[] = new PhabricatorApplicationTransactionValidationError(
              $type,
              pht(''),
              pht('Bugzilla Bug ID:  Bugzilla did not provide an expected response: %s.', $status_code)
            );
          }
        } catch (HTTPFutureResponseStatus $ex) {
          $errors[] = new PhabricatorApplicationTransactionValidationError(
            $type,
            pht(''),
            pht('Bugzilla could not be reached.')
          );
        }
      }
    }

    return $errors;
  }

/* -(  Property View  )------------------------------------------------------ */

  public function shouldAppearInPropertyView() {
    // Should bug id be visible in Differential UI.
    return true;
  }

  public function renderPropertyViewValue(array $handles) {
    $bug_uri = (string) id(new PhutilURI(PhabricatorEnv::getEnvConfig('bugzilla.url')))
      ->setPath($this->getValue());

    return phutil_tag('a', $bug_uri, $this->getValue());
  }

/* -(  List View  )---------------------------------------------------------- */

  // Switched of as renderOnListItem is undefined
  // public function shouldAppearInListView() {
  //   return true;
  // }

  // TODO Find out if/how to implement renderOnListItem
  // It throws Incomplete if not overriden, but doesn't appear anywhere else
  // except of it's definition in `PhabricatorCustomField`

/* -(  Global Search  )------------------------------------------------------ */

  public function shouldAppearInGlobalSearch() {
    return true;
  }

/* -(  Conduit  )------------------------------------------------------------ */

  public function shouldAppearInConduitDictionary() {
    // Should the field appear in `differential.revision.search`
    return true;
  }

  public function shouldAppearInConduitTransactions() {
    // Required if needs to be saved via Conduit (i.e. from `arc diff`)
    return true;
  }

  protected function newConduitSearchParameterType() {
    return new ConduitStringParameterType();
  }

  protected function newConduitEditParameterType() {
    // Define the type of the parameter for Conduit
    return new ConduitStringParameterType();
  }

  public function readFieldValueFromConduit(string $value) {
    return $value;
  }

  public function isFieldEditable() {
    // Has to be editable to be written from `arc diff`
    return true;
  }

  public function shouldDisableByDefault() {
    return false;
  }

  public function shouldOverwriteWhenCommitMessageIsEdited() {
    return true;
  }
}
