<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends BaseController
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        parent::__construct();
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $response = null;

        if ($user_id = $request->get('user_id')) {
            $response = $this->repository->getUsersJobs($user_id);
        } elseif ($this->isAdminOrSuperAdmin($request)) {
            $response = $this->repository->getAll($request);
        }

        return $this->sendResponse($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return $this->sendResponse($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->store($this->getAuthenticatedUser($request), $data);

        return $this->sendResponse($response);
    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $data = $request->all();
        $cuser = $this->getAuthenticatedUser($request);
        $response = $this->repository->updateJob($id, array_except($data, ['_token', 'submit']), $cuser);

        return $this->sendResponse($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();
        $response = $this->repository->storeJobEmail($data);

        return $this->sendResponse($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        $response = null;

        if ($user_id = $request->get('user_id')) {
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
        }

        return $this->sendResponse($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $this->getAuthenticatedUser($request);

        $response = $this->repository->acceptJob($data, $user);

        return $this->sendResponse($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $data = $request->get('job_id');
        $user = $this->getAuthenticatedUser($request);

        $response = $this->repository->acceptJobWithId($data, $user);

        return $this->sendResponse($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $this->getAuthenticatedUser($request);

        $response = $this->repository->cancelJobAjax($data, $user);

        return $this->sendResponse($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->endJob($data);

        return $this->sendResponse($response);
    }

    public function customerNotCall(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->customerNotCall($data);

        return $this->sendResponse($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $this->getAuthenticatedUser($request);

        $response = $this->repository->getPotentialJobs($user);

        return $this->sendResponse($response);
    }

    public function distanceFeed(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'distance' => 'nullable|numeric',
            'time' => 'nullable|numeric',
            'jobid' => 'required|numeric',
            'session_time' => 'nullable|numeric',
            'flagged' => 'required|boolean',
            'manually_handled' => 'required|boolean',
            'by_admin' => 'required|boolean',
            'admincomment' => 'required_if:flagged,true|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $data = $validator->validated();

        $jobid = $data['jobid'];

        // Update Distance if time or distance is provided
        if (isset($data['time']) || isset($data['distance'])) {
            Distance::where('job_id', '=', $jobid)
                ->update(['distance' => $data['distance'], 'time' => $data['time']]);
        }

        // Update Job if admincomment, session_time, flagged, manually_handled, or by_admin is provided
        if ($data['admincomment'] || $data['session_time'] || $data['flagged'] || $data['manually_handled'] || $data['by_admin']) {
            Job::where('id', '=', $jobid)
                ->update([
                    'admin_comments' => $data['admincomment'],
                    'flagged' => $data['flagged'] ? 'yes' : 'no',
                    'session_time' => $data['session_time'],
                    'manually_handled' => $data['manually_handled'] ? 'yes' : 'no',
                    'by_admin' => $data['by_admin'] ? 'yes' : 'no',
                ]);
        }

        return $this->sendResponse('Record updated!');
    }

    public function reopen(Request $request)
    {
        $data = $request->all();
        $response = $this->repository->reopen($data);

        return $this->sendResponse($response);
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return $this->sendResponse(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->find($data['jobid']);
        $job_data = $this->repository->jobToData($job);

        try {
            $this->repository->sendSMSNotificationToTranslator($job);
            return $this->sendResponse(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return $this->sendResponse(['success' => $e->getMessage()]);
        }
    }

}
