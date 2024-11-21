<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User; 
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class AdminNurseController extends Controller
{
    public function nurseList(Request $request)
    {
        // Return the view with the filtered admin users
        return view('admin.nurseList');
    }

    public function nurseListIndex(Request $request)
    {
        // Get all users and count active admins in one go
        $users = User::all();
        $activeNurseCount = User::where('role', 'nurse')->count();
    
        // Return the view with both variables
        return view('admin.nurseList', compact('users', 'activeNurseCount'));
    }
    
    public function searchNurse(Request $request)
    {
        $query = $request->input('queryNurse');
        
        // Fetching nurse admin matching the search query
        $users = User::where('role', 'nurse')
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                ->orWhere('email', 'LIKE', "%{$query}%")
                ->orWhere('role', 'LIKE', "%{$query}%")
                ->orWhere('gender', 'LIKE', "%{$query}%")
                ->orWhere('staff_id', 'LIKE', "%{$query}%")
                ->orWhereRaw("CAST(ic_number AS CHAR) LIKE ?", ["%{$query}%"])
                ->orWhereRaw("CAST(contact_number AS CHAR) LIKE ?", ["%{$query}%"])
                ->orWhere('address', 'LIKE', "%{$query}%")
                ->orWhere('blood_type', 'LIKE', "%{$query}%");
        })
        ->get();

        return view('admin.searchNurse', compact('users'));
    }

    public function showAddNurseForm()
    {
        return view('admin.addNurse');
    }

    public function addNewNurse(Request $request)
    {
        // Validate the incoming request
        $validatedData = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'in:nurse'],
            'staff_id' => [
                'nullable',
                'string',
                Rule::requiredIf(function () use ($request) {
                    return in_array($request->role, ['nurse']);
                }),
            ],
            'gender' => ['required', 'in:male,female'],
        ]);
    
        // Create the user without checking for existing accounts
        $user = User::create([
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'password' => Hash::make($validatedData['password']),
            'role' => $validatedData['role'],
            'staff_id' => ($validatedData['role'] === 'patient') ? null : $validatedData['staff_id'],
            'gender' => $validatedData['gender'],
            'profile_picture' => 'images/profile.png', // Set default profile picture path
        ]);
    
        // Redirect or return a response
        return redirect()->route('admin.nurseUserdata.show', ['id' => $user->id])->with('success', 'Nurse user created successfully. Please fill in additional information.');
    }

    public function adminshownurse($id)
    {
        $userToEdit = User::findOrFail($id); // Retrieve the user by ID
    
        // Pass the user data to the view
        return view('auth.nurseUserData', compact('userToEdit')); // Pass the variable
    }

    public function adminstorenurse(Request $request, $id)
    {
        // Validate the request
        $request->validate([
            'ic_number' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'blood_type' => 'required|string|max:5',
            'contact_number' => 'required|string|max:15',
            'medical_history' => 'nullable|array',
            'medical_history.*' => 'string',
            'description' => 'nullable|string|max:500',
            'emergency_contact' => 'required|string|max:100',
            'relation' => 'required|string|max:50',
        ]);

        try {
            $user = User::findOrFail($id);
            
            // Update basic information
            $user->ic_number = $request->ic_number;
            $user->address = $request->address;
            $user->blood_type = $request->blood_type;
            $user->contact_number = $request->contact_number;
            $user->description = $request->description;
            $user->emergency_contact = $request->emergency_contact;
            $user->relation = $request->relation;
            
            // Always set profile picture to default
            $user->profile_picture = 'images/profile.png';

            // Handle medical history
            if ($request->has('medical_history')) {
                $medicalHistory = $request->medical_history;
                if (in_array('none', $medicalHistory)) {
                    $user->medical_history = null;
                } else {
                    $user->medical_history = implode(',', $medicalHistory);
                }
            } else {
                $user->medical_history = null;
            }

            $user->save();

            return redirect()->route('nurseList')
                ->with('success', 'Nurse details have been successfully updated.');

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while saving nurse details.')
                ->withInput();
        }
    }
}