<?php namespace Tjphippen\Engarde;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use Hart;

class Engarde
{
    protected $config;
    protected $client;
    protected $baseUri;

    function __construct($config)
    {
        $this->config = $config;
        $this->client = new Client;
        $this->baseUri = 'https://'.$config['subdomain'].'.engarde-acd.com';
        $this->login();
    }

    private function login()
    {
        $page = $this->client->request('GET', $this->baseUri.'/login.aspx');
        $form = $page->selectButton('Login')->form();
        return $this->client->submit($form, [
            'ctl00$mainContent$UserName' => $this->config['username'],
            'ctl00$mainContent$Password' => $this->config['password']
        ]);

//        $cookies = $client->getRequest()->getCookies();
//        foreach ($cookies as $key => $value) {
//            $request->addCookie($key, $value);
//        }
    }

    public function getUsers(){
        $users = [];
        for($p = 1; $p <= 21; $p++){
            $page[$p] = new Crawler(file_get_contents('usersFiles/'.$p.'.html'));
            $users = array_merge($users, $this->pullPageUsers($page[$p]));
        }
//        $users = [
////            [
////                'engardeId' => 500983,
////                'firstname' => 'Evan',
////                'lastname' => 'Fronauer (Kevin Wood)',
////                'active' => 1,
////            ]
//        ];
        $created = 0;
        foreach($users as $user){
            $existing = \App\User::where('options->engardeId', $user['engardeId'])->first();
            dd($existing);
            if(!$existing = \App\User::where('options->engardeId', $user['engardeId'])->first()){
                dd($user);
                $user['email'] = $user['engardeId'].'@tag.net';
                \App\User::create($user);
                $created++;
            }
        }
        echo 'Created '.count($created).' Users';
    }

    public function pullPageUsers($result){
        $rows = $result->filter('#ctl00_mainContent_grdEmployees')->children()->each(function($tr){
            return $tr->children()->filter('.spreadSheet1')->each(function($td){
                return $td->text();
            });
        });
        $i = 0;
        foreach($rows as $eUser){
            if(!empty($eUser)){
                $users[$i]['engardeId'] = $eUser[0];
                $users[$i]['firstname'] = $eUser[1];
                $users[$i]['lastname'] = $eUser[2];
                $users[$i]['active'] = $eUser[8];
                // do getUser function
                $i++;
            }
        }
        return $users;
    }

    public function getUser($number){

        $page = $this->client->request('GET', $this->baseUri.'/Admin/EditEmployee.aspx?PID='.$number);
        $areas = $page->filter('#ctl00_mainContent_rptEditEmployee_ctl00_grdOfficeAssignments')->children()->each(function($tr){
            return $tr->filter('.spreadSheet3')->text();
        });
        $form = $page->selectButton('Save Changes')->form();
        $user = $this->transform($this->userFields(), $form, 'ctl00$mainContent$rptEditEmployee$ctl00$');
        $user['areas'] = $areas;
        return $user;
    }

    public function getLead($number)
    {
        $page = $this->client->request('GET', $this->baseUri.'/Customer/ViewSingleCustomer.aspx?CustomerAccountID='.$number);
        $form = $page->selectButton('Save')->form();
        return $this->transform($this->leadFields(), $form, 'ctl00$mainContent$sb');
    }

    public function getAccount($number)
    {
        $page = $this->client->request('GET', $this->baseUri.'/Customer/CustomerEdit.aspx?CustomerAccountID='.$number);
        $notesDom = '//*[@id="ctl00_mainContent_UpdatePanel1"]/table[2]/tr[5]/td[1]/table[1]/tr[1]/td[3]/table[1]/tr[1]/td[2]/table[1]';
        $tds = array_values(array_filter($page->filterXPath($notesDom)->filter('tr')->each(function($tr){
            return $tr->text();
        })));
        $i = 0;
        foreach($tds as $key => $text){
            if ($key % 3 == 0) {
                list($date, $author) = explode('-', $text);
                $notes[$i]['date'] = (new \Carbon\Carbon(trim($date)))->toDateTimeString();
                $notes[$i]['author'] = trim($author);
            }else{
                if(trim($text)){
                    $notes[$i]['note'] = isset($text) ? trim($text): null;
                }
                $i++;
            }
        }
        $devicesDom = '//*[@id="ctl00_mainContent_UpdatePanel1"]/table[3]/tr[1]/td[1]/table[1]/tr[5]/td[1]/table[1]/tr[1]/td[3]/table[1]';
        $devTds = $page->filterXPath($devicesDom)->filter('tr')->each(function($tr){
            return $tr->children()->each(function($td){
                return trim($td->text());
            });
        });
        if($devs = array_slice($devTds, 1)){
            $i = 0;
            $fields = ['name', 'soldBy', 'points', 'cost', 'price', 'quantity', 'totalPoints', 'totalCost', 'totalPrice', 'net'];
            foreach($devs as $dev){
                $devices[$i] = array_combine($fields, array_except($dev, 0));
                $i++;
            }
        }
        $form = $page->selectButton('Save Changes')->form();
        $account = $this->transform($this->fields(), $form, 'ctl00$mainContent$UpdateForm$');
        $account['repPaid'] = $page->filter('#ctl00_mainContent_UpdateForm_lblDatePaid')->text();
        return array_merge($account, compact('notes', 'devices'));
    }

    public function getCredit($number){
        $page = $this->client->request('GET', $this->baseUri.'/Customer/CreditReportHistory.aspx?CustomerAccountID='.$number);
        $link = $page->filterXPath('//*[@id="ctl00_mainContent_grdCreditChecks"]')->filter('.spreadSheet3 a');
        if($link->count() > 0) {
            $reportPage = $this->client->request('GET', $this->baseUri . '/Customer/' . $link->attr('href'));
            $htmlReport = $reportPage->filterXPath('//*[@id="Content"]/table[1]/tr[2]/td[1]');
            $transactionId = $reportPage->filter('#hartTransactionId')->count() > 0 ? $reportPage->filter('#hartTransactionId .value')->text() : null;

            if(!empty($transactionId) && $reportPage->filter('.alert')->count() > 0) {
                $credit['reference'] = 0;
                $credit['transaction'] = $transactionId;
                $credit['bureau'] = 'EFX';
                $credit['score'] = trim($reportPage->filter('.alert')->text());

                list($end, $bureau, $date) = explode(' - ', $htmlReport->filter('p')->last()->text());
                $credit['created_at'] = (new \Carbon\Carbon($date))->toDateTimeString();

                $startOf = '<img src="/images/Clear.gif" style="width: 1px; height: 10px" alt=""><br>';
                $endOf = '<span id="ctl00_mainContent_lblCreditErrMsg"><font color="Red"></font></span>';
                $endOf2 = '<div id="hartTransactionId">
<label>Transaction ID:</label> <span class="value">' . $credit['transaction'] . '</span>
</div>';
                $credit['html'] = str_replace($endOf2, '', str_replace($endOf, '</body>
</html>', str_replace($startOf, '<html>
<head>', $htmlReport->html())));

                return $credit;
            }
        }
    }

    public function getAlarmSystem($number, $type)
    {
        $customerUri = $type == 'CSG' ? '/CSG/EditSystem.aspx' : '/Customer/AddSystem.aspx';
        $page = $this->client->request('GET', $this->baseUri.$customerUri.'?CustomerAccountID='.$number);
        $form = $page->selectButton('Save')->form();
        return $this->transform($this->alarmFields(), $form, 'ctl00$mainContent$');
    }

    public function getAdc($number)
    {
        $page = $this->client->request('GET', $this->baseUri.'/Customer/Alarm.aspx?CustomerAccountID='.$number);
        $accountId = $page->filter('#ctl00_mainContent_lbl_AlarmCustomerID')->text();

        $forwarding = $page->filter('#ctl00_mainContent_hf_CentralStationForwardingOption')->attr('_text');
//        $forwarding = $page->filter('#ctl00_mainContent_ddl_CentralStationForwardingOption')->attr('_text');
//        dd($accountId, $forwarding);
        return array_filter(compact('accountId', 'forwarding'));

    }

    public function getTicketNotes($number)
    {
        $page = $this->client->request('GET', $this->baseUri.'/TroubleTickets/TicketNotes.aspx?TicketId='.$number);
        $notesDom = '//*[@id="ctl00_mainContent_grdTicketNotes"]';
        return $tds = $page->filterXPath($notesDom)->filter('tr')->each(function($tr){
            return $tr->children()->filter('.spreadSheet1')->each(function($td){
                return trim($td->text());
            });
        });
    }

    public function getTicket($number)
    {
        $page = $this->client->request('GET', $this->baseUri.'/TroubleTickets/ViewSingleTroubleTicket.aspx?TicketID='.$number);
        $form = $page->selectButton('Update Ticket')->form();
        $ticket = $this->transform($this->ticketFields(), $form, 'ctl00$mainContent$boxTicket$drp');
        $ticket['id'] = $number;
        $ticket['accountId'] = $page->filter('#ctl00_mainContent_boxTicket_lnkCustomerAccountID')->text();
        $ticket['created'] = (new \Carbon\Carbon($page->filter('#ctl00_mainContent_boxTicket_lblDateCreated')->text()))->toDateTimeString();
        $ticket['createdBy'] = $page->filter('#ctl00_mainContent_boxTicket_lblCreatedBy')->text();
        $notesDom = '//*[@class="note"]';
        $tds = $page->filterXPath($notesDom)->filter('tr')->each(function($tr){
            return current($tr->children()->each(function($td){
                return trim($td->text());
            }));
        });
        $notes = $this->getTicketNotes($number);
        $i = 0; $comments = [];
        foreach($tds as $key => $value){
            if ($key % 2 == 0) {
                if($value != 'Description:') {
                    list($author, $date) = explode(' - ', $value);
                    $comments[$i]['author'] = $author;
                    $comments[$i]['datetime'] = isset($notes[$key/2][0]) ? (new \Carbon\Carbon($notes[$key/2][0]))->toDateTimeString() : null;
                }
            }else{
                if(!isset($comments[$i]['author']) && !isset($comments[$i]['datetime'])) {
                    $ticket['description'] = $value;
                }elseif(strpos($value, '(Closed)') !== false || $value == '(Finalized)'){
                    $ticket['closed'] = $comments[$i]['datetime'];
                    unset($comments[$i]);
                }elseif($value == '(Open)'){
                    $ticket['created'] = $comments[$i]['datetime'];
                    unset($comments[$i]);
                }else{
                    $comments[$i]['body'] = $value;
                }
                $i++;
            }

        }
        $ticket['comments'] = $comments;
        return $ticket;
    }

    private function transform($fields, $form, $nm)
    {
        $result = [];
        foreach($fields as $field => $name){
            if(current($parts = preg_split('/[\\/|-]/', $name)) != $name){
                $i = 0;
                foreach($parts as $part){
                    $values[$i] = $form->get($nm.$part)->getValue();
                    $i++;
                }
                $value = strpos($name, '/') ? implode('/', array_filter($values)) : implode('-', array_filter($values));
            }else{
                if(method_exists($this, $name)){
                    $value = call_user_func(array($this, $name), $form->get($nm.$name)->getValue());
                }else{
                    $value = $form->has($nm.$name) ? $form->get($nm.$name)->getValue() : null;
                }
            }
            if($value){
                array_set($result, $field, $value);
            }
        }
        return array_filter($result);
    }


    public function Status($id){
        $statuses = [
            1 => 'Open',
            2 => 'Closed',
            3 => 'Finalized'
        ];
        return $id ? $statuses[$id] : null;
    }

    public function TicketType($id){
        $types = [
            2 => 'Hold',
            1 => 'Information',
            3 => 'Work Order - No Pay',
            4 => 'Work Order - Paid'
        ];
        return $id ? $types[$id] : null;
    }

    public function ticketReasonId($reason){
        $reasons = [
            '33 System Uninstall' => 'System Uninstall',
            '1***CSG 3rd Party Service***' => 'N/A',
            '14 Customer Question' => 'System Training',
            '30 Service Scheduling' => 'Inspection',
            '2**CSG After 90 Day Service**' => 'N/A',
            '32 System Move' => 'System Move',
            '19 Panel Battery Issue' => 'Panel Low Battery',
            '8 Camera Install' => 'Camera Install',
            '31 System Inspection' => 'Inspection',
            '7 Adding Equipment' => 'Adding Equipment',
            '26 Sales Issue' => 'Complicance Issue',
            '28 Sensor False Alarming' => 'False Alarming',
            '9 Cancel at door/rescheduled' => 'N/A',
            '3**CSG within 90 Day Service**' => 'N/A',
            '5 2WV Not Working' => '2 Way Voice Issue',
            '27 Sensor Battery Issue' => 'Sensor Low Battery',
            '16 Finish Install' => 'Adding Equipment',
            '6 AC power failure' => 'AC Power Failure',
            '17 Line Seizure Fail' => 'Line Seizure Failure',
            '13 Compliance Issue' => 'Complicance Issue',
            '20 Paperwork Fail' => 'Complicance Issue',
            '24 Remounting Sensors ' => 'Remount Sensor',
            '4*CSG Signal Service*' => 'Signal Service',
            '21 Phone Line Issue' => 'Phone Line Issue',
            '11 Cell Unit Issues' => 'Cell Unit Issue',
            '12 Closed Over the phone ' => 'N/A',
            '29 Sensor Not Communicating' => 'Zone Trouble',
            '34 Takeover Sensor Replacement' => 'Replace Takeover Equip.',
            '18 No show' => 'N/A',
            '10 Car Repairs' => 'N/A',
            '15 Customer Retraining' => 'System Training',
            '22 QC Call' => 'Complicance Issue',
            '35 ZWAVE Malfunction' => 'Zwave Malfunction',
            '25 Retention Customer ' => 'N/A',
            '36 Started Install' => 'N/A',
            '23 QC Fail' => 'Complicance Issue',
            '37 Funding Service' => 'Complicance Issue',
        ];
        return $reason ? $reasons[$reason] : 'N/A';
    }


    public function TicketReason($id){
        $reasons = [
            17 => '1***CSG 3rd Party Service***',
            46 => '2**CSG After 90 Day Service**',
            32 => '3**CSG within 90 Day Service**',
            33 => '4*CSG Signal Service*',
            34 => '5 2WV Not Working',
            29 => '6 AC power failure',
            21 => '7 Adding Equipment',
            45 => '8 Camera Install',
            43 => '9 Cancel at door/rescheduled',
            41 => '10 Car Repairs',
            24 => '11 Cell Unit Issues',
            36 => '12 Closed Over the phone ',
            27 => '13 Compliance Issue',
            6 => '14 Customer Question',
            39 => '15 Customer Retraining',
            38 => '16 Finish Install',
            25 => '17 Line Seizure Fail',
            42 => '18 No show',
            31 => '19 Panel Battery Issue',
            18 => '20 Paperwork Fail',
            23 => '21 Phone Line Issue',
            35 => '22 QC Call',
            44 => '23 QC Fail',
            22 => '24 Remounting Sensors ',
            40 => '25 Retention Customer ',
            16 => '26 Sales Issue',
            19 => '27 Sensor Battery Issue',
            28 => '28 Sensor False Alarming',
            37 => '29 Sensor Not Communicating',
            20 => '30 Service Scheduling',
            30 => '31 System Inspection',
            9 => '32 System Move',
            10 => '33 System Uninstall',
            26 => '34 Takeover Sensor Replacement',
            47 => '35 ZWAVE Malfunction',
            48 => '36 Started Install',
            49 => '37 Funding Service',
        ];
        return $id ? $this->ticketReasonId($reasons[$id]) : null;
    }

    public function StatusID($id){
        $statuses = [
            1 => 'Scheduled',
            17 => 'Re-Scheduled',
            18 => 'Past Due',
            19 => 'Cancel Pending',
            20 => 'Service Customer',
            101 => 'Retention',
            21 => 'Charged Back',
            3 => 'Installed',
            5 => 'Cancelled',
            13 => 'Collections',
            16 => 'No Show',
        ];
        return $id ? $statuses[$id] : null;
    }

    public function EquipmentStatusID($id){
        $statuses = [
            1 => 'Not Installed',
            2 => 'Installed',
            3 => 'System Pulled',
        ];
        return $id ? $statuses[$id] : null;
    }

    public function PanelType($id){
        $types = [
            'DIGI' => [
                'description' => 'Landline',
                'type' => 'landline',
                'twoway' => false,
            ],
            'DW2W' => [
                'description' =>'Landline W/ 2-Way',
                'type' =>'landline',
                'twoway' => true,
            ],
            'DWCB' => [
                'description' =>'Landline W/ Cell Backup',
                'type' =>'cellbackup',
                'twoway' => false,
            ],
            'D2CB' => [
                'description' =>'Landline W/ 2-Way &amp; Cell Backup',
                'type' =>'cellbackup',
                'twoway' => true,
            ],
            'CPDB' => [
                'description' =>'Cell Primary',
                'type' =>'cell',
                'twoway' => false,
            ],
            'CP2W' => [
                'description' =>'Cell Primary w/2Way',
                'type' =>'cell',
                'twoway' => true,
            ],
        ];
        return isset($types[$id]) ? $types[$id] : null;
    }

    public function ContactRelationship($id){
        $relationships = [
            'DLR' => 'Dealer',
            'EMP' => 'Employee',
            'FRND' => 'Friend',
            'JAN' => 'Janitorial',
            'MNT' => 'Maintenance',
            'MGR' => 'Manager',
            'NGH' => 'Neighbor',
            'SEC' => 'On Site',
            'OWN' => 'Owner',
            'REL' => 'Relative',
            'RES' => 'Resident',
        ];
        return $id ? $relationships[$id] : null;
    }

    public function Contact2Relationship($id){
        return $this->ContactRelationship($id);
    }

    public function ContactPhoneType($id){
        $types = [
            'CL' => 'Cell',
            'FX' => 'Fax',
            'HM' => 'Home',
            'PG' => 'Pager',
            'WK' => 'Work',
        ];
        return $id ? $types[$id] : null;
    }

    public function Contact2PhoneType($id){
        return $this->ContactPhoneType($id);
    }

    public function MonitoringCompany($id){
        $companies = [
            1006 => ['name' => 'Security Networks', 'value' => 'sn'],
            1 => ['name' => 'Monitronics', 'value' => 'moni'],
            4 => ['name' => 'CSG', 'value' => 'csg'],
            0 => ['name' => 'Avantguard', 'value' => 'ag'],
            1101 => ['name' => 'Followup Resched'],
            1102 => ['name' => 'Verified CXL'],
        ];
        return isset($companies[$id]) ? $companies[$id] : null;
    }

    public function CellProvider($id){
        $providers = [
            '9' => '',
            '10' => '**No Texts**',
            '1' => 'Alltel',
            '2' => 'AT&T',
            '51' => 'Bell South',
            '3' => 'Boost Mobile',
            '22' => 'Cincinnati bell wireless',
            '12' => 'Cingular',
            '11' => 'Cricket',
            '13' => 'FIDO',
            '53' => 'Koodo',
            '21' => 'Metro PCS',
            '4' => 'Nextel',
            '52' => 'Rogers',
            '5' => 'Sprint PCS',
            '6' => 'T-Mobile',
            '54' => 'Telus',
            '20' => 'US Cellular',
            '7' => 'Verizon',
            '50' => 'Virgin Mobile',
            '8' => 'Virgin Mobile USA'
        ];
        return $id ? $providers[$id] : null;
    }

    public function StoneFilesPosition($id){
        $positions = [
            '18' => 'Admin',
            '26' => 'Appointment Setter',
            '5' => 'Assistant Sales Manager',
            '19' => 'Corporate Office Administrator',
            '23' => 'Lead Rep',
            '17' => 'Lead Technician',
            '6' => 'Office Administrator',
            '25' => 'Other',
            '21' => 'Recruiter',
            '22' => 'Regional Recruiter',
            '1' => 'Regional Sales Manager',
            '2' => 'Sales Agent',
            '4' => 'Sales Manager',
            '16' => 'Staff',
            '24' => 'Sub Dealer',
            '14' => 'Team Leader',
            '3' => 'Technician',
            '20' => 'Trainer',
        ];
        return $id ? $positions[$id] : null;
    }

    public function BillingMethodID($id){
        $methods = [
            0 => 'None',
            1 => 'Credit Card',
            2 => 'Check',
            3 => 'eCheck',
            4 => 'Manual Billing',
        ];
        return $id ? $methods[$id] : null;
    }

    private function fields()
    {
        return [
            'status' => 'StatusID',
            'online' => 'hdnIsAccountOnline',
            'equipmentStatus' => 'EquipmentStatusID',
            'office' => 'OfficeID',
            'leadSource' => 'ddlLeadSource',
            'entered.by' => 'EnteredBy',
            'entered.time' => 'TimeEntered',
            'repId' => 'SalesRepID',
            'techId' => 'TechnicianID',
            'setterId' => 'AppointmentSetter',
            'appointmentId' => 'hdnAppointmentID',
            'saleDate' => 'dtSale$txtMonth/dtSale$txtDay/dtSale$txtYear',
            'monitoring.company' => 'MonitoringCompany',
            'monitoring.type' => 'CompanyType',
            'monitoring.id' => 'MonitoringID',
            'monitoring.confirmation' => 'ConfirmationNumber',
            'monitoring.signalsConfirmation' => 'SignalsConfirmationNumber',
            'abortCode' => 'AbortCode',
            'panel' => 'PanelType',
            'language' => 'Language',
            'prefix' => 'Salutation',
            'firstName' => 'FirstName',
            'middleInitial' => 'MiddleInitial',
            'lastName' => 'LastName',
            'suffix' => 'NameSuffix',
            'dob' => 'dtOfBirth$txtMonth/dtOfBirth$txtDay/dtOfBirth$txtYear',
            'company' => 'Company',
            'ssn' => 'SSN1$ssntxt1-SSN1$ssntxt2-SSN1$ssntxt3',
            'creditScore' => 'CreditScore',
            'homePhone' => 'HomePhone1-HomePhone2-HomePhone3',
            'cellPhone' => 'CellPhone1-CellPhone2-CellPhone3',
            'officePhone' => 'OfficePhone1-OfficePhone2-OfficePhone3',
            'email' => 'Email',
            'address1' => 'Address1',
            'address2' => 'Address2',
            'address3' => 'Address3',
            'crossStreet' => 'CrossStreet',
            'subDivision' => 'Subdivision',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'Zip',
            'county' => 'County',
            'country' => 'drpCountry',
            'owner.firstName' => 'txtOwnerFirstName',
            'owner.lastName' => 'txtOwnerLastName',
            'owner.address1' => 'txtOwnerAddress1',
            'owner.address2' => 'txtOwnerAddress2',
            'owner.address3' => 'txtOwnerAddress3',
            'owner.city' => 'txtOwnerCity',
            'owner.state' => 'txtOwnerState',
            'owner.zip' => 'txtOwnerZip',
            'preInstall' => 'QA',
            'postInstall' => 'PostInstallQA',
            'paperworkStatus' => 'PaperworkStatus',
            'repPaper' => 'txtRepPaperMonth/txtRepPaperDay/txtRepPaperYear',
            'techPaper' => 'txtTechPaperMonth/txtTechPaperDay/txtTechPaperYear',
            'submitted' => 'txtSubmittedMonth/txtSubmittedDay/txtSubmittedYear',
            'funded' => 'txtFundedMonth/txtFundedDay/txtFundedYear',
            'chargeback' => 'txtChargedBackMonth/txtChargedBackDay/txtChargedBackYear',
            'saved.date' => 'dtSaved$txtMonth/dtSaved$txtDay/dtSaved$txtYear',
            'saved.by' => 'SavedByID',
            'cancelled.date' => 'dtCancellation$txtMonth/dtCancellation$txtDay/dtCancellation$txtYear',
            'cancelled.reason' => 'CancellationReasons',
            'installed' => 'dtInstall$txtMonth/dtInstall$txtDay/dtInstall$txtYear',
            'arrival' => 'TechArrivalTime',
            'departure' => 'TechDepartureTime',
            'monthsWaived' => 'MonthsWaived',
            'activation.amount' => 'ActivationFeeAmount',
            'activation.payment' => 'ActivationFeePaymentType',
            'rebate.0.check' => 'RebateCheckNumber1',
            'rebate.0.amount' => 'RebateCheckAmount1',
            'rebate.1.check' => 'RebateCheckNumber2',
            'rebate.1.amount' => 'RebateCheckAmount2',
            'billing.firstName' => 'ChFirstName',
            'billing.lastName' => 'ChLastName',
            'billing.address1' => 'ChAddress1',
            'billing.address2' => 'ChAddress2',
            'billing.city' => 'ChCity',
            'billing.state' => 'ChState',
            'billing.zip' => 'ChZip',
            'billing.frequency' => 'ddlBillingFrequency',
            'billing.date' => 'BillingDayOfMonth',
            'billing.term' => 'ContractTerm',
            'billing.extension' => 'dtExtension$txtMonth/dtExtension$txtDay/dtExtension$txtYear',
            'billing.mmr' => 'MonthlyMonitoringRate',
            'billing.startDate' => 'dtBillingStart$txtMonth/dtBillingStart$txtDay/dtBillingStart$txtYear',
            'billing.endDate' => 'dtBillingEnd$txtMonth/dtBillingEnd$txtDay/dtBillingEnd$txtYear',
            'billing.method.type' => 'BillingMethodID',
            'billing.method.checkNumber' => 'CheckNumber',
            'billing.method.routingNumber' => 'RoutingNumber',
            'billing.method.accountNumber' => 'AccountNumber',
            'billing.method.cardNumber' => 'CardNumber',
            'billing.method.expiration' => 'ExpirationMonth/ExpirationYear',
            'collections.status' => 'CollectionsStatusID',
            'collections.date' => 'dtCollections$txtMonth/dtCollections$txtDay/dtCollections$txtYear',
            'collections.amount' => 'CollectionsAmount',
            'contacts.0.firstName' => 'ContactFirstName',
            'contacts.0.lastName' => 'ContactLastName',
            'contacts.0.relationship' => 'ContactRelationship',
            'contacts.0.phone.number' => 'ContactPhone1-ContactPhone2-ContactPhone3',
            'contacts.0.phone.type' => 'ContactPhoneType',
            'contacts.1.firstName' => 'Contact2FirstName',
            'contacts.1.lastName' => 'Contact2LastName',
            'contacts.1.relationship' => 'Contact2Relationship',
            'contacts.1.phone.number' => 'Contact2Phone1-Contact2Phone2-Contact2Phone3',
            'contacts.1.phone.type' => 'Contact2PhoneType',
        ];
    }

    private function alarmFields()
    {
        return [
            'timezone' => 'drpTimeZone',
            'siteType' => 'drpSiteType',
            'installType' => 'drpInstallType',
            'systemType' => 'drpSystemType',
            'serviceType' => 'drpServiceType',
            '2ndSystemType' => 'drpSecondarySystemType',
            '2ndServiceType' => 'drpSecondaryServiceType',
            'serialNumber' => 'txtRadioSerialNumber',
            'dslVoip' => 'rdoDSLVOIP',
            'contract' => 'drpContractStatus',
            'panelLocation' => 'txtPanelLocation',
            'csId' => 'txtCSIDNumber',
            'panelPhone' => 'txtPanelPhoneNumber1-txtPanelPhoneNumber2-txtPanelPhoneNumber3',
            'connectionType' => 'drpConnectionType',
            'reportFormat' => 'drpReportFormat',
            'receiverNumber' => 'txtReceiverPhoneNumber1-txtReceiverPhoneNumber2-txtReceiverPhoneNumber3',
            'abortCode' => 'txtAbortCode',
        ];
    }

    private function userFields()
    {
        return [
            'id' => 'StoneFilesID',
            'user' => 'UserName',
            'email' => 'Email',
            'password' => 'Password',
            'firstname' => 'FirstName',
            'lastname' => 'LastName',
            'cellPhone' => 'CellPhone1-CellPhone2-CellPhone3',
            'cellProvider' => 'CellProvider',
            'homePhone' => 'HomePhone1-HomePhone2-HomePhone3',
            'address1' => 'Address1',
            'address2' => 'Address2',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'Zip',
            'dob' => 'BirthMonth/BirthDay/BirthYear',
            'ssn' => 'SSN1-SSN2-SSN3',
            'position' => 'StoneFilesPosition',
        ];
    }

    private function leadFields($ac = 'AccountContact$')
    {
        return [
            'repId' => 'GeneralInfo$drpSalesRep',
            'setterId' => 'GeneralInfo$drpApptSetter',
            'firstname' => $ac.'txtFirstName',
            'middleinitial' => $ac.'txtMiddleInitial',
            'lastname' => $ac.'txtLastName',
            'secondname' => $ac.'txtSecondaryName',
            'suffix' => $ac.'drpNameSuffix',
            'street.number' => $ac.'txtStreetNumber',
            'street.direction' => $ac.'drpStreetDirection',
            'street.name' => $ac.'txtStreetName',
            'street.type' => $ac.'drpStreetType',
            'apartment' => $ac.'txtApartment',
            'city' => $ac.'txtCity',
            'state' => $ac.'txtState',
            'zip' => $ac.'txtZip',
            'county' => $ac.'txtCounty',
            'country' => $ac.'drpCountry',
            'phone' => $ac.'phHomePhone$AreaCode-'.$ac.'phHomePhone$Prefix-'.$ac.'phHomePhone$Suffix',
            'phone2' => $ac.'phPhone2$AreaCode-'.$ac.'phPhone2$Prefix-'.$ac.'phPhone2$Suffix',
            'email' => $ac.'txtEmail',
            'ssn' => $ac.'txtOwnerSSN1$ssntxt1-'.$ac.'txtOwnerSSN1$ssntxt2-'.$ac.'txtOwnerSSN1$ssntxt3',
            'dob' => $ac.'dtDOB$txtMonth/'.$ac.'dtDOB$txtDay/'.$ac.'dtDOB$txtYear',
            'married' => $ac.'ddlMarried',
        ];
    }

    private function ticketFields()
    {
        return [
            'status' => 'Status',
            'type' => 'TicketType',
            'reason' => 'TicketReason',
            'assignedTo' => 'AssignedToID$drpUserList',
        ];
    }



}


