<?php

namespace Modules\Iappointment\Services;

use Modules\Iappointment\Entities\Appointment;
use Modules\Ichat\Entities\Conversation;

class AppointmentService
{
  public $appointment;
  public $category;
  public $userRepository;
  public $notificationService;
  public $checkinShiftRepository;
  public $settingsApiController;
  public $permissionsApiController;
  public $conversationService;

  public function __construct()
  {
    $this->appointment = app('Modules\Iappointment\Repositories\AppointmentRepository');
    $this->category = app('Modules\Iappointment\Repositories\CategoryRepository');
    $this->userRepository = app('Modules\Iprofile\Repositories\UserApiRepository');
    $this->notificationService = app("Modules\Notification\Services\Inotification");
    $this->checkinShiftRepository = app("Modules\Icheckin\Repositories\ShiftRepository");
    $this->settingsApiController = app("Modules\Ihelpers\Http\Controllers\Api\SettingsApiController");
    $this->permissionsApiController = app("Modules\Ihelpers\Http\Controllers\Api\PermissionsApiController");
    $this->conversationService = app("Modules\Ichat\Services\ConversationService");
  }

  function assign($categoryId = null, $subscription = false, $customerId = null)
  {

    $customerUser =  $this->userRepository->getItem($subscription->entity_id ?? $customerId ?? null, json_decode(json_encode(['filter' => []])));

    $categoryParams = [
      'include' => [],
      'filter' => [],
    ];

    $category = $this->category->getItem($categoryId, json_decode(json_encode($categoryParams)));

    if (isset($customerUser->id)) {
      \Log::info("Creating new Appointment");
      $appointmentExist = Appointment::where('customer_id', $customerUser->id)
        ->where('category_id', $categoryId)
        ->whereIn('status_id', [1,2,3])->count(); //count if the customer has active appointments
      if ($appointmentExist == 0) {
        //create new appointment in case of non-active appointments for the customer
        $appointmentData = [
          'description' => $category->title,
          'customer_id' => $customerUser->id,
          'status_id' => 1,
          'category_id' => $category->id,
        ];
        $appointment = $this->appointment->create($appointmentData); //create an appointment

        $this->notificationService = app("Modules\Notification\Services\Inotification");
        //send notification to customer from new appointment
        $this->notificationService->to([
          "email" => $customerUser->email,
          "broadcast" => [$customerUser->id],
          "push" => [$customerUser->id],
        ])->push(
          [
            "title" => trans("iappointment::appointments.messages.newAppointment"),
            "message" => trans("iappointment::appointments.messages.newAppointmentContent", ['name' => $customerUser->present()->fullName, 'detail' => $appointment->description]),
            "icon_class" => "fas fa-list-alt",
            "buttonText" => trans("iappointment::appointments.button.take"),
            "withButton" => true,
            "link" => url('/ipanel/#/appointments/customer/' . $appointment->id),
            "setting" => [
              "saveInDatabase" => 1 // now, the notifications with type broadcast need to be save in database to really send the notification
            ],
            "mode" => "modal",
            "actions" => [

              [
                "label" => "Continuar",
                "color" => "warning"
              ],
              [
                "label" => trans("iappointment::appointments.button.take"),
                "toVueRoute" => [
                  "name" => "qappointment.panel.appointments.index",
                  "params" => [
                    "id" => $appointment->id
                  ]
                ],
              ]
            ]
          ]
        );
      }
    }

    $roleToAssigned = setting('iappointment::roleToAssigned'); //get the proffesional role assigned in settings

    $userParams = [
      'filter' => [
        'roleId' => $roleToAssigned,
      ]
    ];

    $users = $this->userRepository->getItemsBy(json_decode(json_encode($userParams))); //get proffesional users

    $userAssignedTo = null;

    foreach ($users as $professionalUser) {
      $canBeAssigned = false; //check if the user can be assigned
  
      //get professional settings
      $professionalSettings = $this->settingsApiController->getAll(['userId' => $professionalUser->id]);

      // buscando cantidad de citas ya asignadas al profesional
      $appointmentCount =
        Appointment::where('assigned_to', $professionalUser->id)
          ->where(function ($query) use ($professionalUser) {
            $query->where('customer_id', '<>', $professionalUser->id)
              ->orWhereNull('customer_id');
          });
      
      // si el usuario tiene categorias asignadas entonces se filtra
      if (!empty($professionalSettings['appointmentCategories']))
        $appointmentCount->whereIn('category_id', $professionalSettings['appointmentCategories'] ?? []);
  
      // donde las citas tengan estado 2 y 3, pre y convesation estatuses
      $appointmentCount = $appointmentCount->whereIn('status_id', [2, 3])->count(); //count the active appointments for the proffesional

      // obteniendo el maximo de citas posibles al mismo tiempo del profesional
      $maxAppointments = $professionalSettings['maxAppointments'] ?? setting('iappointment::maxAppointments');

      \Log::info("Appointment count for {$professionalUser->present()->fullName} > $appointmentCount - $maxAppointments");

      //obtiene citas con estado 1 (Pendiente) y no estén asignadas al profesional
      $appointmentsToAssign = Appointment::where('status_id', 1)->where('customer_id', '<>', $professionalUser->id)->get();

      foreach ($appointmentsToAssign as $appointmentToAssign) {
        //check if the user can be assigned to an appointment
        if (isset($professionalSettings['appointmentCategories']) && !empty($professionalSettings['appointmentCategories'])) {
          if (!in_array($appointmentToAssign->category_id, $professionalSettings['appointmentCategories'])) {
            $canBeAssigned = false;
            continue;
          }
        }
        
        // si las citas asignadas al profesional no superan el maximo permitido
        if ($appointmentCount < $maxAppointments) {
          //search active proffesional shifts
          $shiftParams = [
            'include' => [],
            'user' => $professionalUser,
            'take' => false,
            'filter' => [
              'repId' => $professionalUser->id,
              'active' => '1',
            ]
          ];
          $canBeAssigned = true;
          //if shifts are enabled, then find it for the proffesional
          if (setting('iappointment::enableShifts') === '1') {
            if (is_module_enabled('Icheckin')) {
              $shifts = $this->checkinShiftRepository->getItemsBy(json_decode(json_encode($shiftParams)));
              if (count($shifts) > 0) {
                $canBeAssigned = true;
              } else {
                $canBeAssigned = false;
                \Log::info("User {$professionalUser->present()->fullName} does not have active shifts");
              }
            }
          }
          
          //finalmente si es posible asignar la cita
          if ($canBeAssigned) {
            //assign the professional
            $customerUser = $appointmentToAssign->customer;
            $prevAssignedTo = $appointmentToAssign->assignedTo;
  
            // se busca el historial de appointment en caso de que ya haya pasado a conversation
            $statusHistory = $appointmentToAssign->statusHistory->where("status_id",3)->first();

            
            $this->appointment->updateBy($appointmentToAssign->id, [
              'assigned_to' => $professionalUser->id,
              'status_id' => isset($statusHistory->id) ? 3 : 2,
            ]); //assign the new proffesional
            
            // se busca la conversacion perteneciente a la cita en caso de que ya exista
            $appointmentConversation = Conversation::where('entity_type', Appointment::class)
              ->where('entity_id', $appointmentToAssign->id)->first();
            
            //check if appointment has a chat conversation, else, create a new conversation
            if (!isset($appointmentConversation->id)) {
              //if the appointment has not a conversation, then create
              $conversationData = [
                'users' => [
                  $professionalUser->id,
                  $customerUser->id,
                ],
                'entity_type' => Appointment::class,
                'entity_id' => $appointmentToAssign->id,
              ];
              //create the conversation
              $this->conversationService->create($conversationData);
              $appointmentConversation = Conversation::where('entity_type', Appointment::class)
                ->where('entity_id', $appointmentToAssign->id)->first();
            }
            
            //assign chat to professional and customer if professional is changed
            if (isset($prevAssignedTo->id) && $prevAssignedTo->id != $professionalUser->id) {
              $appointmentConversation->users()->sync([
                $customerUser->id,
                $professionalUser->id,
              ]);
            }
  
            \Log::info("Appointment #{$appointmentToAssign->id} assigned to user {$professionalUser->present()->fullName}");
            if ($customerUser) {
              $this->notificationService = app("Modules\Notification\Services\Inotification");
              \Log::info("Enviando notificacion al user $customerUser->id, email: $customerUser->email");
              //send email and notification to customer
              $this->notificationService->to([
                "email" => [$customerUser->email],
                "broadcast" => [$customerUser->id],
              ])->push(
                [
                  "title" => trans("iappointment::appointments.messages.newAppointment"),
                  "message" => trans("iappointment::appointments.messages.newAppointmentWithAssignedContent", ['name' => $customerUser->present()->fullName, 'detail' => $appointmentToAssign->category->title, 'assignedName' => $professionalUser->present()->fullName]),
                  "icon_class" => "fas fa-list-alt",
                  "buttonText" => trans("iappointment::appointments.button.take"),
                  "withButton" => true,
                  "link" => url('/ipanel/#/appointments/customer/' . $appointmentToAssign->id),
                  "setting" => [
                    "saveInDatabase" => 1 // now, the notifications with type broadcast need to be save in database to really send the notification
                  ],
                  "mode" => "modal",
                  "actions" => [
          
                    [
                      "label" => "Continuar",
                      "color" => "warning"
                    ],
                    [
                      "label" => trans("iappointment::appointments.button.take"),
                      "toVueRoute" => [
                        "name" => "qappointment.panel.appointments.index",
                        "params" => [
                          "id" => $appointmentToAssign->id
                        ]
                      ]
                    ]
                  ]
                ]
              );
            } else {
              \Log::info("User {$professionalUser->present()->fullName} can't be assigned yet");
            }
          }// end if $canBeAssigned
        }//end if $appointmentCount < $maxAppointments
        else {
          \Log::info("User {$professionalUser->present()->fullName} is out of appointments");
          $canBeAssigned = false;
        }

        if (!$canBeAssigned) {
          //send email to admin emails if the appointment cannot be assigned
          $adminEmails = setting('isite::emails');
          $this->notificationService->to([
            "email" => $adminEmails,
          ])->push(
            [
              "title" => trans("iappointment::appointments.messages.appointmentNotAssigned"),
              "message" => trans("iappointment::appointments.messages.appointmentNotAssignedContent", ['detail' => $appointmentToAssign->category->title]),
              "icon_class" => "fas fa-list-alt",
              "buttonText" => trans("iappointment::appointments.button.take"),
              "withButton" => true,
              "link" => url('/ipanel/#/appointments/customer' . $appointmentToAssign->id),
              "setting" => [
                "saveInDatabase" => 1 // now, the notifications with type broadcast need to be save in database to really send the notification
              ],
            ]
          );
        } // end if !!$canBeAssigned
      } // end foreach appointments
    } // end foreach professionals
    
    
    if(isset($appointment->id)) return $appointment;
  }
}
