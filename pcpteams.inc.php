<?php

define('CIVICRM_PCPTEAM_TYPE_INDIVIDUAL', 0);
define('CIVICRM_PCPTEAM_TYPE_TEAM_MEMBER', 1);
define('CIVICRM_PCPTEAM_TYPE_TEAM', 2);


/**
 * Helper functions.
 */

/**
 * Returns the current pcpblock "team" is_active value.
 * e.g. whether the form allows PCP by teams.
 */
function pcpteams_pcpblockteam_getvalue($target_entity_type, $target_entity_id) {
  $pcpblock = new CRM_PCP_DAO_PCPBlock();
  $pcpblock->target_entity_type = $target_entity_type;
  $pcpblock->target_entity_id = $target_entity_id;

  if ($pcpblock->find(TRUE)) {
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_pcp_block_team WHERE civicrm_pcp_block_id = " . $pcpblock->id);

    if ($dao->fetch()) {
      return $dao->is_active;
    }
  }

  return FALSE;
}

/**
 * Sets the current pcpblock "team" is_active value.
 * e.g. whether the form allows PCP by teams.
 */
function pcpteams_pcpblockteam_setvalue($target_entity_type, $target_entity_id, $pcp_team_active) {
  $pcpblock = new CRM_PCP_DAO_PCPBlock();
  $pcpblock->target_entity_type = $target_entity_type;
  $pcpblock->target_entity_id = $target_entity_id;

  $pcp_team_active = intval($pcp_team_active);

  if ($pcpblock->find(TRUE)) {
    $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_pcp_block_team WHERE civicrm_pcp_block_id = " . $pcpblock->id);

    if ($dao->fetch()) {
      CRM_Core_DAO::executeQuery("UPDATE civicrm_pcp_block_team SET is_active = " . $pcp_team_active . " WHERE civicrm_pcp_block_id = " . $dao->civicrm_pcp_block_id);
    }
    else {
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_pcp_block_team (civicrm_pcp_block_id, is_active)
                                  VALUES ({$pcpblock->id}, $pcp_team_active)");
    }
  }
  else {
    CRM_Core_Error::fatal(ts('Could not find the PCPBlock for entity: %1 %2', array(1 => $target_entity_id, 2 => $target_entity_type)));
  }
}

/**
 * Sets the team for a PCP page.
 * If the team is NULL, assumes it is a new team.
 *
 * @param Int $pcp_id ID of the PCP page in civicrm_pcp
 * @param Int $pcp_team_id ID of the PCP team (NULL means the page is not part of a team).
 * @param Int $pcp_type_id Type of PCP page (CIVICRM_PCPTEAM_TYPE_TEAM, CIVICRM_PCPTEAM_TYPE_TEAM_MEMBER or CIVICRM_PCPTEAM_TYPE_INDIVIDUAL).
 * @param Boolean $notifications Send e-mail notifications to the pcp page owner for each contribution received.
 * @returns void.
 */
function pcpteams_setteam($pcp_id, $pcp_team_id, $pcp_type_id, $notifications = 0) {
  // QuickForms might put null in here, if it was not checked.
  if (! $notifications) {
    $notifications = 0;
  }

  // If it is a team page, make sure we do not allow to be part of another team
  if ($pcp_type_id == CIVICRM_PCPTEAM_TYPE_TEAM || $pcp_type_id == CIVICRM_PCPTEAM_TYPE_INDIVIDUAL) {
    $pcp_team_id = NULL;
  }

  // Strict validation of the type of PCP id, since we don't want bad data in the DB.
  $valid_team_types = array(
    CIVICRM_PCPTEAM_TYPE_TEAM,
    CIVICRM_PCPTEAM_TYPE_TEAM_MEMBER,
    CIVICRM_PCPTEAM_TYPE_INDIVIDUAL,
  );

  if (! in_array($pcp_type_id, $valid_team_types)) {
    CRM_Core_Error::fatal('Invalid PCP type received.');
  }

  // Check if the PCP page already has a record associating it (or not) to a team.
  $params = array(
    1 => array($pcp_id, 'Positive'),
  );

  $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_pcp_team WHERE status_id = 1 AND civicrm_pcp_id = %1", $params);

  if ($dao->fetch()) {
    // FIXME: Consider options for updating
    CRM_Core_DAO::executeQuery("
      UPDATE civicrm_pcp_team
      SET status_id = 1,
        civicrm_pcp_id_parent = " . ($pcp_team_id ? $pcp_team_id : 'NULL') .
        ", type_id = $pcp_type_id
      WHERE civicrm_pcp_id = " . $pcp_id
    );
    // If we were a team before, then update children
    if ($dao->type_id == CIVICRM_PCPTEAM_TYPE_TEAM && $pcp_type_id != CIVICRM_PCPTEAM_TYPE_TEAM) {
      CRM_Core_DAO::executeQuery("
      UPDATE civicrm_pcp_team
      SET civicrm_pcp_id_parent = NULL
      , type_id = " . CIVICRM_PCPTEAM_TYPE_INDIVIDUAL . "
      WHERE civicrm_pcp_id_parent = " . $pcp_id
      );
    }
  }
  else {
    if ($pcp_team_id) {
      $sql = "INSERT INTO civicrm_pcp_team (civicrm_pcp_id, civicrm_pcp_id_parent, status_id, type_id, notify_on_contrib)
                   VALUES (%1, %2, 1, %3, %4)";

      $params = array(
        1 => array($pcp_id, 'Positive'),
        2 => array($pcp_team_id, 'Integer'),
        3 => array($pcp_type_id, 'Integer'),
        4 => array($notifications, 'Integer'),
      );

      CRM_Core_DAO::executeQuery($sql, $params);
    }
    else {
      $sql = "INSERT INTO civicrm_pcp_team (civicrm_pcp_id, civicrm_pcp_id_parent, status_id, type_id, notify_on_contrib)
                   VALUES (%1, NULL, 1, %3, %4)";

      $params = array(
        1 => array($pcp_id, 'Positive'),
        3 => array($pcp_type_id, 'Integer'),
        4 => array($notifications, 'Integer'),
      );

      CRM_Core_DAO::executeQuery($sql, $params);
    }
  }
}

/**
 * Get the civicrm_pcp_team record.
 */
function pcpteams_getteaminfo($pcp_id) {
  $pcp_id = intval($pcp_id);
  $dao = CRM_Core_DAO::executeQuery("SELECT * FROM civicrm_pcp_team WHERE civicrm_pcp_id = " . $pcp_id);

  if ($dao->fetch()) {
    return $dao;
  }

  return NULL;
}

/**
 * Returns a list of PCP-Teams.
 *
 * @param String $component_page_type
 *     Contribute/event (although right now only contribute is fully supported).
 * @param Int    $component_page_id
 *     ID of the contribution page for which we want to list teams.
 * @returns Array
 *     Returns a sorted list of teams, keyed on the PCP ID.
 */
function pcpteams_getteamnames($component_page_type = 'contribute', $component_page_id = NULL) {
  $teams = array();

  $sql = "
    SELECT pcp.id, pcp.title
      FROM civicrm_pcp_team t
      LEFT JOIN civicrm_pcp pcp ON (t.civicrm_pcp_id = pcp.id)
     WHERE civicrm_pcp_id_parent IS NULL
       AND type_id = %1
       AND pcp.is_active = 1
       AND pcp.page_type = %2";

  $params = array(
    1 => array(CIVICRM_PCPTEAM_TYPE_TEAM, 'Positive'),
    2 => array($component_page_type, 'String'),
  );

  if ($component_page_id) {
    $sql .= ' AND pcp.page_id = %3';
    $params[3] = array($component_page_id, 'Positive');
  }

  $dao = CRM_Core_DAO::executeQuery($sql, $params);

  while ($dao->fetch()) {
    $teams[$dao->id] = $dao->title;
  }

  natcasesort($teams);
  return $teams;
}

/**
 * Returns the name of a PCP team by ID
 */
function pcpteams_getteamname($pcp_id) {
  $teams = array();

  $pcp_id = intval($pcp_id);
  $pcp_team_info = pcpteams_getteaminfo($pcp_id);

  if ($pcp_team_info->civicrm_pcp_id_parent) {
    $pcp = new CRM_PCP_DAO_PCP();
    $pcp->id = $pcp_team_info->civicrm_pcp_id_parent;

    if ($pcp->find(TRUE)) {
      return $pcp->title;
    }
  }

  return '';
}

/**
 * Returns the PCP team members, if any.
 * We keep the result cached because it can be called multiple times in a page.
 * Ex: for the member listing, and "total amount raised".
 */
function pcpteams_getmembers($pcp_id, $show_non_approved = FALSE) {
  static $members = array();

  $pcp_id = intval($pcp_id);

  if (isset($members[$pcp_id])) {
    return $members[$pcp_id];
  }

  // Get the status_id for 'approved'
  $pcpStatus  = CRM_Contribute_PseudoConstant::pcpStatus();
  $approved   = CRM_Utils_Array::key(ts('Approved'), $pcpStatus);

  // Get the members of the team
  $dao = CRM_Core_DAO::executeQuery("
    SELECT team.civicrm_pcp_id as id, member.title, member.is_active
      FROM civicrm_pcp_team team
     INNER JOIN civicrm_pcp member ON (member.id = team.civicrm_pcp_id)
     WHERE civicrm_pcp_id_parent = " . $pcp_id
    . ($show_non_approved ? '' : " AND team.status_id = 1 ")
    . ' AND member.status_id = ' . $approved
    . ' ORDER BY member.title asc '
  );

  while ($dao->fetch()) {
    $members[$pcp_id][$dao->id] = array(
      'title' => $dao->title,
      'amount' => CRM_PCP_BAO_PCP::thermoMeter($dao->id),
      'is_active' => $dao->is_active,
    );
  }

  return $members[$pcp_id];
}

/**
 * Calculates the amount raised for a team.
 * For individuals we can used directly CRM_PCP_BAO_PCP::thermoMeter($pcp_id)
 */
function pcpteams_getamountraised($pcp_id) {
  $total = 0;
  $members = pcpteams_getmembers($pcp_id);

  foreach ($members as $key => $val) {
    $total += $val['amount'];
  }

  return $total;
}

function _pcpteams_get_contact ($userId = NULL) {
  $contact_array = NULL;
  if (!isset($userId)) {
    return $contact_array;
  }
  $standard_fields = array(
    'contact_id',
    'contact_type',
    'contact_sub_type',
    'sort_name',
    'display_name',
    'first_name',
    'last_name',
    'id' ,
  );

  $params = array(
    'version' => 3,
    'id' => $userId,
    'return' => $standard_fields,
    'sequential' => '0'
  );

  $contacts = civicrm_api('Contact', 'get', $params);
  if ($contacts['is_error'] == 1) {
    return NULL;
  }
  $contact_array = $contacts['values'][$userId];

  return $contact_array;
}

