<?php
$messages = json_decode($HTTP_RAW_POST_DATA);

$jira_subdomain = getenv('issues.apdbox.com');
$jira_username = getenv('test');
$jira_password = getenv('tester123');
$jira_project = getenv('DBO');
$jira_issue_type = getenv('incident');
$pd_subdomain = getenv('trimble-navigation.pagerduty.com');
$pd_api_token = getenv('VM1oYSzosKnRqSsp5sXj');

if ($messages) foreach ($messages->messages as $webhook) {
  $webhook_type = $webhook->type;
  $incident_id = $webhook->data->incident->id;
  $incident_number = $webhook->data->incident->incident_number;
  $ticket_url = $webhook->data->incident->html_url;
  $pd_requester_id = $webhook->data->incident->assigned_to_user->id;
  $service_name = $webhook->data->incident->service->name;
  $assignee = $webhook->data->incident->assigned_to_user->name;
  if ($webhook->data->incident->trigger_summary_data->subject) { $trigger_summary_data = $webhook->data->incident->trigger_summary_data->subject; }
  else { $trigger_summary_data = $webhook->data->incident->trigger_summary_data->description; }

  $summary = "PagerDuty Service: $service_name, Incident #$incident_number, Summary: $trigger_summary_data";

  switch ($webhook_type) {
    case "incident.trigger":
      $verb = "triggered";

      //Create the JIRA ticket when an incident has been triggered
      $url = "https://$jira_subdomain.atlassian.net/rest/api/2/issue/";

      $data = array('fields'=>array('project'=>array('key'=>"$jira_project"),'summary'=>"$summary",'description'=>"A new PagerDuty ticketh as been created.  Please go to $ticket_url to view it.", 'issuetype'=>array('name'=>"$jira_issue_type")));
      $data_json = json_encode($data);

      $return = http_request($url, $data_json, "POST", "basic", $jira_username, $jira_password);
      $status_code = $return['status_code'];
      $response = $return['response'];
      $response_obj = json_decode($response);
      $response_key = $response_obj->key;

      if ($status_code == "201") {
        //Update the PagerDuty ticket with the JIRA ticket information.
        $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
        $data = array('note'=>array('content'=>"JIRA ticket $response_key has been created.  You can view it at https://$jira_subdomain.atlassian.net/browse/$response_key."),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", $pd_username, $pd_api_token);
      }
      else {
        //Update the PagerDuty ticket if the JIRA ticket isn't made.
        $url = "https://$pd_subdomain.pagerduty.com/api/v1/incidents/$incident_id/notes";
        $data = array('note'=>array('content'=>"A JIRA ticket failed to be created."),'requester_id'=>"$pd_requester_id");
        $data_json = json_encode($data);
        http_request($url, $data_json, "POST", "token", $pd_username, $pd_api_token);
      }
      break;
    default:
      continue;
  }
}

function http_request($url, $data_json, $method, $auth_type, $username, $token) {
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  if ($auth_type == "token") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json),"Authorization: Token token=$token"));
    curl_setopt($ch, CURLOPT_HTTPAUTH);
  }
  else if ($auth_type == "basic") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username:$token");
  }
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_POSTFIELDS,$data_json);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response  = curl_exec($ch);
  $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return array('status_code'=>"$status_code",'response'=>"$response");
}
?>
