<?php

class PaymentController extends \BaseController {


    /**
     *
     * @TODO: Workout exactly what this is used for - I think most of the functionality has been moved elsewhere
     *
     */


    /**
     * @var \BB\Repo\EquipmentRepository
     */
    private $equipmentRepository;
    /**
     * @var \BB\Repo\PaymentRepository
     */
    private $paymentRepository;

    function __construct(
            \BB\Helpers\GoCardlessHelper $goCardless,
            \BB\Repo\EquipmentRepository $equipmentRepository,
            \BB\Repo\PaymentRepository $paymentRepository
        )
    {
        $this->goCardless = $goCardless;
        $this->equipmentRepository = $equipmentRepository;
        $this->paymentRepository = $paymentRepository;

        $this->beforeFilter('role:member', array('only' => ['create', 'destroy']));
        $this->beforeFilter('role:admin', array('only' => ['store']));
    }


    public function index()
    {
        $sortBy = Request::get('sortBy', 'created_at');
        $direction = Request::get('direction', 'desc');
        $dateFilter = Request::get('date_filter', '');
        $this->paymentRepository->setPerPage(50);

        if ($dateFilter) {
            $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $dateFilter)->setTime(0,0,0);
            $this->paymentRepository->dateFilter($startDate, $startDate->copy()->addMonth());
        }

        $payments = $this->paymentRepository->getPaginated(compact('sortBy', 'direction'));

        $dateRangeStart = \Carbon\Carbon::create(2009, 07, 01);
        $dateRange = [];
        while ($dateRangeStart->lt(\Carbon\Carbon::now())) {
            $dateRange[$dateRangeStart->toDateString()] = $dateRangeStart->format('F Y');
            $dateRangeStart->addMonth();
        }

        return View::make('payments.index')->with('payments', $payments)->with('dateRange', $dateRange);
    }


    /**
     * Start the creation of a new gocardless payment
     *   Details get posted into this method and the redirected to gocardless
     * @param $userId
     * @throws \BB\Exceptions\AuthenticationException
     * @throws \BB\Exceptions\FormValidationException
     * @throws \BB\Exceptions\NotImplementedException
     */
	public function create($userId)
	{
        $user = User::findWithPermission($userId);
		if (Input::get('source') == 'gocardless')
        {
            $reason = Input::get('reason');
            if ($reason == 'subscription')
            {
                $name = strtoupper("BBSUB".$user->id.":MANUAL");
                $description = "Monthly Subscription Fee - Manual";
                $amount = $user->monthly_subscription;
                $ref = null;
            }
            elseif ($reason == 'induction')
            {
                $name           = strtoupper("BBINDUCTION".$user->id.":".Input::get('induction_key'));
                $description    = strtoupper(Input::get('induction_key')) . " Induction Fee";
                $ref            = Input::get('induction_key');
                ($item = $this->equipmentRepository->findByKey($ref)) || App::abort(404);
                $amount         = $item->cost;
            }
            elseif ($reason == 'door-key')
            {
                $name           = strtoupper("BBDOORKEY".$user->id);
                $description    = "Door Key Deposit";
                $ref            = null;
                $amount         = 10;
            }
            elseif ($reason == 'storage-box')
            {
                $name           = strtoupper("BBSTORAGEBOX".$user->id);
                $description    = "Storage Box Deposit";
                $ref            = null;
                $amount         = 5;
            }
            elseif ($reason == 'balance')
            {
                $amount         = Input::get('amount') * 1;//convert the users amount into a number
                if (!is_numeric($amount)) {
                    throw new \BB\Exceptions\FormValidationException("Not a valid amount", new \Illuminate\Support\MessageBag(['amount'=>'Invalid amount']));
                }
                $name           = strtoupper("BBBALANCE".$user->id);
                $description    = "BB Credit Payment";
                $ref            = null;
            }
            else
            {
                throw new \BB\Exceptions\NotImplementedException();
            }
            $payment_details = array(
                'amount'        => $amount,
                'name'          => $name,
                'description'   => $description,
                'redirect_uri'      => route('account.payment.confirm-payment', [$user->id]),
                'user'              => [
                    'first_name'        =>  $user->given_name,
                    'last_name'         =>  $user->family_name,
                    'billing_address1'  =>  $user->address_line_1,
                    'billing_address2'  =>  $user->address_line_2,
                    'billing_town'      =>  $user->address_line_3,
                    'billing_postcode'  =>  $user->address_postcode,
                    'country_code'      => 'GB'
                ],
                'state'         => $reason.':'.$ref
            );
            return Redirect::to($this->goCardless->newBillUrl($payment_details));
        }
        else
        {
            //Unsupported for now
            // perhaps manual payments or maybe they should go straight to store
            throw new \BB\Exceptions\NotImplementedException();
        }
	}


    /**
     * Confirm a gocardless payment and create a payment record
     * @param $userId
     * @return mixed
     * @throws \BB\Exceptions\AuthenticationException
     * @throws \BB\Exceptions\NotImplementedException
     */
    public function confirmPayment($userId)
    {
        $confirm_params = array(
            'resource_id'    => $_GET['resource_id'],
            'resource_type'  => $_GET['resource_type'],
            'resource_uri'   => $_GET['resource_uri'],
            'signature'      => $_GET['signature']
        );

        // State is optional
        if (isset($_GET['state'])) {
            $confirm_params['state'] = $_GET['state'];
        }

        $user = User::findWithPermission($userId);

        try
        {
            $confirmed_resource = $this->goCardless->confirmResource($confirm_params);
        }
        catch (\Exception $e)
        {
            $errors = $e->getMessage();
            Notification::error($errors);
            return Redirect::route('account.show', $user->id);
        }

        $details = explode(':',Input::get('state'));
        $reason = $details[0];
        $ref = $details[1];

        $payment = new Payment([
            'reason'            => $reason,
            'source'            => 'gocardless',
            'source_id'         => $confirmed_resource->id,
            'amount'            => $confirmed_resource->amount,
            'fee'               => ($confirmed_resource->amount - $confirmed_resource->amount_minus_fees),
            'amount_minus_fee'  => $confirmed_resource->amount_minus_fees,
            'status'            => $confirmed_resource->status
        ]);
        $payment = $user->payments()->save($payment);

        if ($reason == 'subscription')
        {
            $user->status = 'active';
            $user->active = true;
            $user->save();
        }
        elseif ($reason == 'induction')
        {
            Induction::create([
                'user_id' => $user->id,
                'key' => $ref,
                'paid' => true,
                'payment_id' => $payment->id
            ]);
        }
        elseif ($reason == 'door-key')
        {
            $user->key_deposit_payment_id = $payment->id;
            $user->save();
        }
        elseif ($reason == 'storage-box')
        {
            $user->storage_box_payment_id = $payment->id;
            $user->save();
        }
        elseif ($reason == 'balance')
        {
            $memberCreditService = \App::make('\BB\Services\Credit');
            $memberCreditService->setUserId($user->id);
            $memberCreditService->recalculate();

            //This needs to be improved
            Notification::success("Payment recorded");
            return Redirect::route('account.bbcredit.index', $user->id);
        }
        else
        {
            throw new \BB\Exceptions\NotImplementedException();
        }

        Notification::success("Payment made");
        return Redirect::route('account.show', $user->id);
    }


    /**
     * Store a manual payment
     *
     * @param $userId
     * @throws \BB\Exceptions\AuthenticationException
     * @throws \BB\Exceptions\FormValidationException
     * @throws \BB\Exceptions\NotImplementedException
     * @return Response
     */
	public function store($userId)
	{
        $user = User::findWithPermission($userId);

        if (!Auth::user()->hasRole('admin')) {
            throw new \BB\Exceptions\AuthenticationException;
        }

        $reason = Input::get('reason');

        if ($reason == 'subscription')
        {
            $payment = new Payment([
                'reason'            => $reason,
                'source'            => Input::get('source'),
                'source_id'         => '',
                'amount'            => $user->monthly_subscription,
                'amount_minus_fee'  => $user->monthly_subscription,
                'status'            => 'paid'
            ]);
            $user->payments()->save($payment);

            $user->extendMembership(Input::get('source'), \Carbon\Carbon::now()->addMonth());

        }
        elseif ($reason == 'induction')
        {
            if (Input::get('source') == 'manual')
            {
                $ref = Input::get('induction_key');
                ($item = $this->equipmentRepository->findByKey($ref)) || App::abort(404);
                $payment = new Payment([
                    'reason'            => $reason,
                    'source'            => 'manual',
                    'source_id'         => '',
                    'amount'            => $item->cost,
                    'amount_minus_fee'  => $item->cost,
                    'status'            => 'paid'
                ]);
                $payment = $user->payments()->save($payment);
                Induction::create([
                    'user_id' => $user->id,
                    'key' => $ref,
                    'paid' => true,
                    'payment_id' => $payment->id
                ]);
            }
            else
            {
                throw new \BB\Exceptions\NotImplementedException();
            }
        }
        elseif ($reason == 'door-key')
        {
            $payment = new Payment([
                'reason'            => $reason,
                'source'            => Input::get('source'),
                'source_id'         => '',
                'amount'            => 10,
                'amount_minus_fee'  => 10,
                'status'            => 'paid'
            ]);
            $user->payments()->save($payment);

            $user->key_deposit_payment_id = $payment->id;
            $user->save();

        }
        elseif ($reason == 'storage-box')
        {
            $payment = new Payment([
                    'reason'            => $reason,
                    'source'            => Input::get('source'),
                    'source_id'         => '',
                    'amount'            => 5,
                    'amount_minus_fee'  => 5,
                    'status'            => 'paid'
                ]);
            $user->payments()->save($payment);

            $user->storage_box_payment_id = $payment->id;
            $user->save();
        }
        elseif ($reason == 'balance')
        {
            $amount         = Input::get('amount') * 1;//convert the users amount into a number
            if (!is_numeric($amount)) {
                throw new \BB\Exceptions\FormValidationException("Not a valid amount", new \Illuminate\Support\MessageBag(['amount'=>'Invalid amount']));
            }
            $payment = new Payment([
                'reason'            => 'balance',
                'source'            => Input::get('source'),
                'source_id'         => '',
                'amount'            => $amount,
                'amount_minus_fee'  => $amount,
                'status'            => 'paid'
            ]);
            $user->payments()->save($payment);

            $memberCreditService = \App::make('\BB\Services\Credit');
            $memberCreditService->setUserId($user->id);
            $memberCreditService->recalculate();

            //This needs to be improved
            Notification::success("Payment recorded");
            return Redirect::route('account.bbcredit.index', $user->id);
        }
        else
        {
            throw new \BB\Exceptions\NotImplementedException();
        }
        Notification::success("Payment recorded");
        return Redirect::route('account.show', $user->id);
	}


	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show($id)
	{
		//
	}


	/**
	 * Show the form for editing the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function edit($id)
	{
		//
	}


	/**
	 * Update the specified resource in storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function update($id)
	{
		//
	}


	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id)
	{
		//
	}


}
