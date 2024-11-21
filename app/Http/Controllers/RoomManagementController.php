<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Bed;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class RoomManagementController extends Controller
{
    public function index()
    {
        $rooms = Room::with(['beds' => function($query) {
            $query->orderBy('bed_number');
        }])
        ->withCount(['beds as available_beds' => function($query) {
            $query->where('status', 'available');
        }])
        ->orderBy('room_number')
        ->get();

        // Get all nurses for the schedule form
        $nurses = User::where('role', 'nurse')->get();
        
        return view('nurseAdmin.roomManagement', compact('rooms', 'nurses'));
    }

    public function editBeds(Request $request)
    {
        $search = $request->input('search');
        
        $patients = User::where('role', 'patient')
            ->when($search, function($query) use ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                    ->orWhere('ic_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('gender', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('blood_type', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('emergency_contact', 'like', "%{$search}%");
                });
            })
            ->select('id', 'name', 'ic_number', 'email', 'gender', 'address', 'blood_type', 'contact_number', 'emergency_contact')
            ->orderBy('name')
            ->get();

        return view('nurseAdmin.editBeds', compact('patients', 'search'));
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            // Validate request
            $validated = $request->validate([
                'room_number' => 'required|string|unique:rooms,room_number',
                'floor' => 'required|integer|min:1',
                'type' => 'required|in:ward,private,icu',
                'total_beds' => 'required|integer|min:1',
                'notes' => 'nullable|string|max:255'
            ]);

            // Create room
            $room = Room::create([
                'room_number' => $validated['room_number'],
                'floor' => $validated['floor'],
                'type' => $validated['type'],
                'total_beds' => $validated['total_beds'],
                'notes' => $validated['notes']
            ]);

            // Create beds for the room
            for ($i = 1; $i <= $validated['total_beds']; $i++) {
                Bed::create([
                    'room_id' => $room->id,
                    'bed_number' => $i,
                    'patient_id' => null,
                    'status' => 'available'
                ]);
            }

            DB::commit();
            return redirect()->back()->with('success', 'Room created successfully');
        } catch (Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Failed to create room: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'room_number' => 'required|unique:rooms,room_number,' . $id,
            'floor' => 'required|integer|min:1',
            'type' => 'required|string',
            'total_beds' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255'
        ]);

        try {
            DB::beginTransaction();
            
            $room = Room::findOrFail($id);
            $currentBedCount = $room->beds()->count();
            $newTotalBeds = $request->input('total_beds');

            // Update room details
            $room->update([
                'room_number' => $request->input('room_number'),
                'floor' => $request->input('floor'),
                'type' => $request->input('type'),
                'total_beds' => $newTotalBeds,
                'notes' => $request->input('notes'),
            ]);

            // Handle bed capacity changes
            if ($newTotalBeds > $currentBedCount) {
                // Add new beds
                for ($i = $currentBedCount + 1; $i <= $newTotalBeds; $i++) {
                    Bed::create([
                        'room_id' => $room->id,
                        'bed_number' => $i,
                        'patient_id' => null,
                        'status' => 'available'
                    ]);
                }
            } elseif ($newTotalBeds < $currentBedCount) {
                // Remove excess beds (only if they're not occupied)
                $bedsToRemove = $room->beds()
                    ->where('status', '!=', 'occupied')
                    ->orderByDesc('bed_number')
                    ->limit($currentBedCount - $newTotalBeds)
                    ->get();

                if ($bedsToRemove->count() < ($currentBedCount - $newTotalBeds)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot reduce beds: Some beds are currently occupied'
                    ], 422);
                }

                foreach ($bedsToRemove as $bed) {
                    $bed->delete();
                }
            }

            DB::commit();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update room: ' . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => 'Failed to update room: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            
            // Find the room
            $room = Room::findOrFail($id);
            
            // Check for occupied beds
            if ($room->beds()->where('status', 'occupied')->exists()) {
                return back()->with('error', 'Cannot delete room: Some beds are currently occupied');
            }
            
            // Delete all beds associated with the room
            $room->beds()->delete();
            
            // Delete the room
            $room->delete();
            
            DB::commit();
            return redirect()->route('nurseadmin.roomList')
                ->with('success', 'Room and associated beds deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error deleting room: ' . $e->getMessage());
            return back()->with('error', 'Failed to delete room. Please try again later.');
        }
    }

    public function addBed(Request $request, Room $room)
    {
        try {
            // Get the next bed number
            $nextBedNumber = $room->beds()->max('bed_number') + 1;

            // Create new bed
            $bed = Bed::create([
                'room_id' => $room->id,
                'bed_number' => $nextBedNumber,
                'patient_id' => null,
                'status' => 'available'
            ]);

            // Update room's total beds
            $room->increment('total_beds');

            return response()->json([
                'success' => true,
                'bed' => $bed,
                'message' => 'Bed added successfully'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add bed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateBed(Request $request, Bed $bed)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'status' => 'required|in:available,occupied,maintenance',
                'patient_id' => 'nullable|exists:users,id'
            ]);

            // If setting to maintenance, clear patient_id
            if ($validated['status'] === 'maintenance') {
                $validated['patient_id'] = null;
            }

            // If setting to available, clear patient_id
            if ($validated['status'] === 'available') {
                $validated['patient_id'] = null;
            }

            // If setting to occupied, require patient_id
            if ($validated['status'] === 'occupied' && !$validated['patient_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient ID is required when status is occupied'
                ], 422);
            }

            $bed->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Bed updated successfully',
                'bed' => $bed->fresh()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update bed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function removeBed(Bed $bed)
    {
        try {
            // Check if bed is occupied
            if ($bed->status === 'occupied') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot remove occupied bed'
                ], 400);
            }

            DB::beginTransaction();
            $bed->delete();
            $bed->room()->decrement('total_beds');
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bed removed successfully'
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove bed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function edit($id)
    {
        try {
            $room = Room::findOrFail($id);
            
            // If the request wants JSON (AJAX request)
            if (request()->ajax()) {
                return response()->json([
                    'success' => true,
                    'id' => $room->id,
                    'room_number' => $room->room_number,
                    'floor' => $room->floor,
                    'total_beds' => $room->total_beds,
                    'available_beds' => $room->available_beds,
                    'type' => $room->type,
                    'notes' => $room->notes
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Error fetching room: ' . $e->getMessage());
            
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch room details'
                ], 500);
            }

            return back()->with('error', 'Failed to fetch room details');
        }
    }

    public function getBeds($roomId)
    {
        try {
            $room = Room::findOrFail($roomId);
            $beds = $room->beds()
                ->with('patient:id,name') // Only get necessary patient fields
                ->orderBy('bed_number')
                ->get();
            
            return response()->json([
                'success' => true,
                'beds' => $beds,
                'room' => [
                    'id' => $room->id,
                    'room_number' => $room->room_number
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching beds: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch beds'
            ], 500);
        }
    }

    public function searchPatients(Request $request)
    {
        $term = $request->input('term');
        
        $patients = User::where('role', 'patient')
            ->where(function($query) use ($term) {
                $query->where('name', 'LIKE', "%{$term}%")
                      ->orWhere('email', 'LIKE', "%{$term}%")
                      ->orWhere('gender', 'LIKE', "%{$term}%")
                      ->orWhere('address', 'LIKE', "%{$term}%")
                      ->orWhere('blood_type', 'LIKE', "%{$term}%")
                      ->orWhere('contact_number', 'LIKE', "%{$term}%")
                      ->orWhere('emergency_contact', 'LIKE', "%{$term}%");
            })
            ->select('id', 'name', 'email', 'gender', 'blood_type', 'contact_number')
            ->limit(10)
            ->get();
        
        return response()->json($patients);
    }

    public function getPatientDetails($id)
    {
        try {
            $patient = User::where('id', $id)
                          ->where('role', 'patient')
                          ->select(
                              'id',
                              'name',
                              'staff_id',
                              'gender',
                              'email',
                              'ic_number',
                              'address',
                              'blood_type',
                              'contact_number',
                              'emergency_contact'
                          )
                          ->first();
    
            if (!$patient) {
                return response()->json(null);
            }
    
            return response()->json($patient);
        } catch (\Exception $e) {
            \Log::error('Error fetching patient details: ' . $e->getMessage());
            return response()->json(null, 500);
        }
    }

    public function manageBed(Request $request)
    {
        try {
            $bed = Bed::findOrFail($request->bed_id);
            
            switch ($request->action) {
                case 'assign':
                    $bed->patient_id = $request->patient_id;
                    $bed->status = 'occupied';
                    break;
                    
                case 'maintenance':
                    $bed->patient_id = null;
                    $bed->status = 'maintenance';
                    break;
                    
                case 'transfer':
                    $newBed = Bed::findOrFail($request->new_bed_id);
                    $newBed->patient_id = $bed->patient_id;
                    $newBed->status = 'occupied';
                    $bed->patient_id = null;
                    $bed->status = 'available';
                    $newBed->save();
                    break;
            }
            
            $bed->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Bed updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update bed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function dischargeBed($bedId)
    {
        try {
            $bed = Bed::findOrFail($bedId);
            $bed->patient_id = null;
            $bed->status = 'maintenance';
            $bed->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Patient discharged and bed set to maintenance'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to discharge patient: ' . $e->getMessage()
            ], 500);
        }
    }

    public function searchUnassignedPatients(Request $request)
    {
        try {
            $search = $request->search;
            
            $patients = User::where('role', 'patient')
                ->whereNotExists(function ($query) {
                    $query->select('patient_id')
                        ->from('beds')
                        ->whereColumn('users.id', 'beds.patient_id')
                        ->whereNotNull('patient_id');
                })
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('ic_number', 'like', "%{$search}%")
                        ->orWhere('contact_number', 'like', "%{$search}%");
                })
                ->select([
                    'id',
                    'name',
                    'ic_number',
                    'contact_number',
                    'gender',
                    'blood_type'
                ])
                ->orderBy('name')
                ->paginate(10);

            return response()->json([
                'patients' => $patients
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch patients: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAllPatients()
    {
        try {
            $patients = DB::select("SELECT id, name, ic_number, contact_number FROM users WHERE role = 'patient' ORDER BY name ASC");
            return response()->json($patients);
        } catch (\Exception $e) {
            \Log::error('Error fetching patients: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch patients'
            ], 500);
        }
    }
} 