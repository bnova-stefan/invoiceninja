<?php namespace App\Http\Controllers;

use Auth;
use Utils;
use View;
use URL;
use Input;
use Session;
use Redirect;
use Cache;
use App\Models\Client;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Credit;
use App\Models\Task;
use App\Ninja\Repositories\ClientRepository;
use App\Services\ClientService;
use App\Http\Requests\ClientRequest;
use App\Http\Requests\CreateClientRequest;
use App\Http\Requests\UpdateClientRequest;

class ClientController extends BaseController
{
    protected $clientService;
    protected $clientRepo;
    protected $entityType = ENTITY_CLIENT;

    public function __construct(ClientRepository $clientRepo, ClientService $clientService)
    {
        //parent::__construct();

        $this->clientRepo = $clientRepo;
        $this->clientService = $clientService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        return View::make('list', [
            'entityType' => ENTITY_CLIENT,
            'title' => trans('texts.clients'),
            'sortCol' => '4',
            'columns' => Utils::trans([
              'checkbox',
              'client',
              'contact',
              'email',
              'date_created',
              'last_login',
              'balance',
              ''
            ]),
        ]);
    }

    public function getDatatable()
    {
        $search = Input::get('sSearch');
        $userId = Auth::user()->filterId();

        return $this->clientService->getDatatable($search, $userId);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(CreateClientRequest $request)
    {
        $client = $this->clientService->save($request->input());

        Session::flash('message', trans('texts.created_client'));

        return redirect()->to($client->getRoute());
    }

    /**
     * Display the specified resource.
     *
     * @param  int      $id
     * @return Response
     */
    public function show(ClientRequest $request)
    {
        $client = $request->entity();
        $user = Auth::user();

        $actionLinks = [];
        if($user->can('create', ENTITY_TASK)){
            $actionLinks[] = ['label' => trans('texts.new_task'), 'url' => URL::to('/tasks/create/'.$client->public_id)];
        }
        if (Utils::hasFeature(FEATURE_QUOTES) && $user->can('create', ENTITY_INVOICE)) {
            $actionLinks[] = ['label' => trans('texts.new_quote'), 'url' => URL::to('/quotes/create/'.$client->public_id)];
        }

        if(!empty($actionLinks)){
            $actionLinks[] = \DropdownButton::DIVIDER;
        }

        if($user->can('create', ENTITY_PAYMENT)){
            $actionLinks[] = ['label' => trans('texts.enter_payment'), 'url' => URL::to('/payments/create/'.$client->public_id)];
        }

        if($user->can('create', ENTITY_CREDIT)){
            $actionLinks[] = ['label' => trans('texts.enter_credit'), 'url' => URL::to('/credits/create/'.$client->public_id)];
        }

        if($user->can('create', ENTITY_EXPENSE)){
            $actionLinks[] = ['label' => trans('texts.enter_expense'), 'url' => URL::to('/expenses/create/0/'.$client->public_id)];
        }

        $token = $client->getGatewayToken();

        $data = [
            'actionLinks' => $actionLinks,
            'showBreadcrumbs' => false,
            'client' => $client,
            'credit' => $client->getTotalCredit(),
            'title' => trans('texts.view_client'),
            'hasRecurringInvoices' => Invoice::scope()->where('is_recurring', '=', true)->whereClientId($client->id)->count() > 0,
            'hasQuotes' => Invoice::scope()->invoiceType(INVOICE_TYPE_QUOTE)->whereClientId($client->id)->count() > 0,
            'hasTasks' => Task::scope()->whereClientId($client->id)->count() > 0,
            'gatewayLink' => $token ? $token->gatewayLink() : false,
            'gatewayName' => $token ? $token->gatewayName() : false,
        ];

        return View::make('clients.show', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(ClientRequest $request)
    {
        if (Client::scope()->withTrashed()->count() > Auth::user()->getMaxNumClients()) {
            return View::make('error', ['hideHeader' => true, 'error' => "Sorry, you've exceeded the limit of ".Auth::user()->getMaxNumClients().' clients']);
        }

        $data = [
            'client' => null,
            'method' => 'POST',
            'url' => 'clients',
            'title' => trans('texts.new_client'),
        ];

        $data = array_merge($data, self::getViewModel());

        return View::make('clients.edit', $data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int      $id
     * @return Response
     */
    public function edit(ClientRequest $request)
    {
        $client = $request->entity();

        $data = [
            'client' => $client,
            'method' => 'PUT',
            'url' => 'clients/'.$client->public_id,
            'title' => trans('texts.edit_client'),
        ];

        $data = array_merge($data, self::getViewModel());

        if (Auth::user()->account->isNinjaAccount()) {
            if ($account = Account::whereId($client->public_id)->first()) {
                $data['planDetails'] = $account->getPlanDetails(false, false);
            }
        }

        return View::make('clients.edit', $data);
    }

    private static function getViewModel()
    {
        return [
            'data' => Input::old('data'),
            'account' => Auth::user()->account,
            'sizes' => Cache::get('sizes'),
            'paymentTerms' => Cache::get('paymentTerms'),
            'currencies' => Cache::get('currencies'),
            'customLabel1' => Auth::user()->account->custom_client_label1,
            'customLabel2' => Auth::user()->account->custom_client_label2,
        ];
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int      $id
     * @return Response
     */
    public function update(UpdateClientRequest $request)
    {
        $client = $this->clientService->save($request->input(), $request->entity());

        Session::flash('message', trans('texts.updated_client'));

        return redirect()->to($client->getRoute());
    }

    public function bulk()
    {
        $action = Input::get('action');
        $ids = Input::get('public_id') ? Input::get('public_id') : Input::get('ids');
        $count = $this->clientService->bulk($ids, $action);

        $message = Utils::pluralize($action.'d_client', $count);
        Session::flash('message', $message);

        if ($action == 'restore' && $count == 1) {
            return Redirect::to('clients/'.Utils::getFirst($ids));
        } else {
            return Redirect::to('clients');
        }
    }
}
