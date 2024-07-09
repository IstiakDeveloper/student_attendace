<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Helpers\FingerHelper;

use App\Http\Controllers\Controller;

use App\Http\Requests\FingerDevice\StoreRequest;

use App\Http\Requests\FingerDevice\UpdateRequest;

use App\Jobs\GetAttendanceJob;

use App\Models\FingerDevices;

use App\Models\Employee;
use App\Models\Attendance;
use App\Models\Leave;

use Gate;

use Illuminate\Http\RedirectResponse;

use Rats\Zkteco\Lib\ZKTeco;

use Symfony\Component\HttpFoundation\Response;

class BiometricDeviceController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $devices = FingerDevices::all();

        return view('admin.fingerDevices.index', compact('devices'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.fingerDevices.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreRequest $request): RedirectResponse
    {
        $helper = new FingerHelper();

        $device = $helper->init($request->input('ip'));

        if ($device->connect()) {
            // Serial Number Sample CDQ9192960002\x00

            $serial = $helper->getSerial($device);

            FingerDevices::create($request->validated() + ['serialNumber' => $serial]);

            flash()->success('Success', 'Biometric Device created successfully !');
        } else {
            flash()->error('Oops', ' Failed connecting to Biometric Device !');
        }

        return redirect()->route('finger_device.index');
    }

    public function show(FingerDevices $fingerDevice)
    {
        return view('admin.fingerDevices.show', compact('fingerDevice'));
    }

    public function edit(FingerDevices $fingerDevice)
    {
        return view('admin.fingerDevices.edit', compact('fingerDevice'));
    }

    public function update(UpdateRequest $request, FingerDevices $fingerDevice): RedirectResponse
    {
        $fingerDevice->update($request->validated());

        flash()->success('Success', 'Biometric Device Updated successfully !');

        return redirect()->route('finger_device.index');
    }
    public function destroy(FingerDevices $fingerDevice): RedirectResponse
    {
        try {
            $fingerDevice->delete();
        } catch (\Exception $e) {
            toast("Failed to delete {$fingerDevice->name}", 'error');
        }

        flash()->success('Success', 'Biometric Device deleted successfully !');

        return back();
    }

    public function addEmployee(FingerDevices $fingerDevice): RedirectResponse
    {
        $device = new ZKTeco($fingerDevice->ip, 4370);

        $device->connect();

        $deviceUsers = collect($device->getUser())->pluck('uid');

        $employees = Employee::select('name', 'id')
            ->whereNotIn('id', $deviceUsers)
            ->get();

        $i = 1;

        foreach ($employees as $employee) {
            $device->setUser($i++, $employee->id, $employee->name, '', '0', '0');
        }
        flash()->success('Success', 'All Employees added to Biometric device successfully!');

        return back();
    }

    public function getAttendance(FingerDevices $fingerDevice)
    {
        try {
            $device = new ZKTeco($fingerDevice->ip, 4370);

            if (!$device->connect()) {
                flash()->error('Failed to connect to the device.');
                return back();
            }

            $data = $device->getAttendance();

            foreach ($data as $value) {
                if ($employee = Employee::whereId($value['id'])->first()) {
                    $timestamp = strtotime($value['timestamp']);
                    $attendanceDate = date('Y-m-d', $timestamp);
                    $attendanceTime = date('H:i:s', $timestamp);

                    if ($value['type'] == 0) { // Attendance
                        if (!Attendance::whereAttendance_date($attendanceDate)
                                ->whereEmp_id($value['id'])
                                ->whereType(0)
                                ->first()) {
                            
                            $att_table = new Attendance();
                            $att_table->uid = $value['uid'];
                            $att_table->emp_id = $value['id'];
                            $att_table->state = $value['state'];
                            $att_table->attendance_time = $attendanceTime;
                            $att_table->attendance_date = $attendanceDate;
                            $att_table->type = $value['type'];
                            $att_table->status = ($employee->schedules->first()->time_in >= $att_table->attendance_time) ? 1 : 0;

                            if ($att_table->status == 0) {
                                AttendanceController::lateTimeDevice($value['timestamp'], $employee);
                            }
                            $att_table->save();
                        }
                    } else { // Leave
                        if (!Leave::whereLeave_date($attendanceDate)
                                ->whereEmp_id($value['id'])
                                ->whereType(1)
                                ->first()) {
                            
                            $lve_table = new Leave();
                            $lve_table->uid = $value['uid'];
                            $lve_table->emp_id = $value['id'];
                            $lve_table->state = $value['state'];
                            $lve_table->leave_time = $attendanceTime;
                            $lve_table->leave_date = $attendanceDate;
                            $lve_table->type = $value['type'];
                            $lve_table->status = ($employee->schedules->first()->time_out <= $lve_table->leave_time) ? 1 : 0;

                            if ($lve_table->status == 0) {
                                LeaveController::overTimeDevice($value['timestamp'], $employee);
                            }
                            $lve_table->save();
                        }
                    }
                } else {
                    Log::warning("Employee not found for ID: {$value['id']}");
                }
            }

            flash()->success('Success', 'Attendance Queue will run in a minute!');
        } catch (\Exception $e) {
            Log::error('Error retrieving attendance data: ' . $e->getMessage());
            flash()->error('Oops', 'Failed to retrieve attendance data.');
        }

        return back();
    }

    
}
