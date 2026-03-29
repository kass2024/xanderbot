<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\HumanHandoffTimeoutService;
use App\Services\WhatsAppAudioConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class InboxController extends Controller
{

/*
|--------------------------------------------------------------------------
| INBOX
|--------------------------------------------------------------------------
*/

public function index(Request $request)
{
$filter = $request->get('filter','all');
$search = $request->get('search');
$conversationId = $request->get('conversation');

Log::info('Inbox page opened',[
'filter'=>$filter,
'search'=>$search,
'conversation'=>$conversationId
]);

$query = Conversation::query();

if ($filter === 'unread') {
$query->whereHas('messages', function ($q) {
$q->where('direction','incoming')
->where('is_read',0);
});
}

if ($filter === 'human')  $query->where('status','human');
if ($filter === 'bot')    $query->where('status','bot');
if ($filter === 'closed') $query->where('status','closed');

if ($search) {
$query->where(function ($q) use ($search) {
$q->where('customer_name','like',"%$search%")
->orWhere('customer_email','like',"%$search%")
->orWhere('phone_number','like',"%$search%");
});
}

$conversations = $query
->with(['agent'])
->withCount([
'messages as unread_count'=>function($q){
$q->where('direction','incoming')
->where('is_read',0);
}
])
->orderByDesc('last_activity_at')
->paginate(20);

$activeConversation = null;

if ($conversationId) {
$activeConversation = Conversation::with([
'messages'=>function($q){
$q->orderBy('created_at','asc');
},
'agent'
])->find($conversationId);

if ($activeConversation) {

app(HumanHandoffTimeoutService::class)->checkAndRelease($activeConversation->fresh());

$activeConversation = Conversation::with([
'messages'=>function($q){
$q->orderBy('created_at','asc');
},
'agent'
])->find($activeConversation->id);

Log::info('Conversation opened',[
'conversation_id'=>$activeConversation->id,
'phone'=>$activeConversation->phone_number
]);

Message::where('conversation_id',$activeConversation->id)
->where('direction','incoming')
->where('is_read',0)
->update([
'is_read'=>1,
'read_at'=>now()
]);

}
}

return view('admin.inbox.index',compact(
'conversations',
'activeConversation',
'filter',
'search'
));
}


/*
|--------------------------------------------------------------------------
| ADMIN REPLY (TEXT + ATTACHMENTS)
|--------------------------------------------------------------------------
*/

public function reply(Request $request, Conversation $conversation)
{

$request->validate([
    'message' => 'nullable|string|max:5000',
    'attachment' => 'nullable|file|max:25600|mimes:jpeg,jpg,png,gif,webp,pdf,doc,docx,mp3,m4a,ogg,opus,wav,webm,mp4,mov,3gp',
]);

if (! $request->hasFile('attachment') && ! filled(trim((string) $request->message))) {
    return back()->withErrors(['message' => 'Enter a message or attach a file.']);
}

$text = $request->message;

Log::info('Admin sending message',[
'conversation'=>$conversation->id,
'phone'=>$conversation->phone_number,
'message'=>$text
]);
$conversation->update([
    'last_activity_at' => now(),
    'last_message_at'  => now()
]);
/*
|--------------------------------------------------------------------------
| HANDLE FILE UPLOAD
|--------------------------------------------------------------------------
*/

$mediaType = null;
$fileUrl = null;
$filename = null;
$path = null;

if ($request->hasFile('attachment')) {

    $file = $request->file('attachment');

    $path = $file->store('whatsapp', 'public');

    $filename = $file->getClientOriginalName();

    $mime = (string) $file->getMimeType();

    if (str_contains($mime, 'image')) {
        $mediaType = 'image';
    } elseif (str_starts_with($mime, 'audio/')) {
        $mediaType = 'audio';
        $absolute = Storage::disk('public')->path($path);
        $convertedAbs = app(WhatsAppAudioConverter::class)->toWhatsAppFormat($absolute);
        if ($convertedAbs && is_file($convertedAbs)) {
            if ($convertedAbs !== $absolute && is_file($absolute)) {
                @unlink($absolute);
            }
            $path = dirname($path).'/'.basename($convertedAbs);
            $filename = basename($convertedAbs);
        }
    } elseif (str_starts_with($mime, 'video/')) {
        $mediaType = 'video';
    } else {
        $mediaType = 'document';
    }

    $fileUrl = URL::to(Storage::disk('public')->url($path));

}


/*
|--------------------------------------------------------------------------
| STORE MESSAGE
|--------------------------------------------------------------------------
*/

$displayContent = $text;
if ($mediaType === 'audio' && ($displayContent === null || $displayContent === '')) {
    $displayContent = '🎤 Voice note';
}

$message = Message::create([
    'conversation_id' => $conversation->id,
    'direction' => 'outgoing',
    'content' => $displayContent ?? '',
    'type' => $mediaType ? 'media' : 'text',
    'media_type' => $mediaType,
    'media_url' => $fileUrl,
    'filename' => $filename,
    'status' => 'sending',
    'is_read' => 1,
    'source' => 'agent',
]);


/*
|--------------------------------------------------------------------------
| WHATSAPP CONFIG
|--------------------------------------------------------------------------
*/

$phoneNumberId = config('services.whatsapp.phone_number_id');
$token         = config('services.whatsapp.access_token');

$endpoint =
config('services.whatsapp.graph_url').'/'
.config('services.whatsapp.graph_version').'/'
.$phoneNumberId.'/messages';


Log::info('Sending WhatsApp request',[
'endpoint'=>$endpoint,
'phone_number_id'=>$phoneNumberId
]);


/*
|--------------------------------------------------------------------------
| SEND MESSAGE
|--------------------------------------------------------------------------
*/

try {

$response = null;

if (! $mediaType) {

    $response = Http::withToken($token)
        ->timeout(config('services.api.timeout'))
        ->post($endpoint, [
            'messaging_product' => 'whatsapp',
            'to' => $conversation->phone_number,
            'type' => 'text',
            'text' => [
                'body' => $text,
            ],
        ]);

} elseif ($mediaType === 'image') {

    $response = Http::withToken($token)
        ->timeout(config('services.api.timeout'))
        ->post($endpoint, [
            'messaging_product' => 'whatsapp',
            'to' => $conversation->phone_number,
            'type' => 'image',
            'image' => [
                'link' => $fileUrl,
            ],
        ]);

} elseif ($mediaType === 'video') {

    $response = Http::withToken($token)
        ->timeout(config('services.api.timeout'))
        ->post($endpoint, [
            'messaging_product' => 'whatsapp',
            'to' => $conversation->phone_number,
            'type' => 'video',
            'video' => [
                'link' => $fileUrl,
            ],
        ]);

} elseif ($mediaType === 'document') {

    $response = Http::withToken($token)
        ->timeout(config('services.api.timeout'))
        ->post($endpoint, [
            'messaging_product' => 'whatsapp',
            'to' => $conversation->phone_number,
            'type' => 'document',
            'document' => [
                'link' => $fileUrl,
                'filename' => $filename ?? 'file',
            ],
        ]);

} elseif ($mediaType === 'audio') {

    $response = Http::withToken($token)
        ->timeout(config('services.api.timeout'))
        ->post($endpoint, [
            'messaging_product' => 'whatsapp',
            'to' => $conversation->phone_number,
            'type' => 'audio',
            'audio' => [
                'link' => $fileUrl,
            ],
        ]);

}

if ($response === null) {
    throw new \RuntimeException('No WhatsApp payload built.');
}

Log::info('WhatsApp API response', [
    'status' => $response->status(),
    'body' => $response->json(),
]);


if ($response->successful()) {

$wamid=$response->json()['messages'][0]['id'] ?? null;

$message->update([
'status' => 'sent',
'external_message_id' => $wamid
]);
} else {

$message->update([
'status'=>'failed'
]);

Log::error('WhatsApp send failed',[
'body'=>$response->body()
]);

}

} catch (\Throwable $e) {

$message->update([
'status'=>'failed'
]);

Log::error('WhatsApp exception',[
'error'=>$e->getMessage()
]);

}


/*
|--------------------------------------------------------------------------
| UPDATE CONVERSATION
|--------------------------------------------------------------------------
*/

$conversation->update([
    'status' => 'human',
    'last_activity_at' => now(),
    'escalation_started_at' => $conversation->escalation_started_at ?? now(),
]);

return back();

}



/*
|--------------------------------------------------------------------------
| BOT / HUMAN SWITCH
|--------------------------------------------------------------------------
*/

public function toggle(Conversation $conversation)
{

$newStatus = $conversation->status === 'bot'
? 'human'
: 'bot';

Log::info('Conversation mode switched',[
'conversation'=>$conversation->id,
'status'=>$newStatus
]);

if ($newStatus === 'bot') {
    $conversation->update([
        'status' => 'bot',
        'assigned_agent_id' => null,
        'escalation_started_at' => null,
    ]);
} else {
    $conversation->update([
        'status' => 'human',
        'escalation_started_at' => now(),
    ]);
}

return back();

}


/*
|--------------------------------------------------------------------------
| LIVE CHAT FETCH
|--------------------------------------------------------------------------
*/

public function fetchMessages(Conversation $conversation)
{

app(HumanHandoffTimeoutService::class)->checkAndRelease($conversation->fresh());
$conversation = $conversation->fresh();

$messages = $conversation->messages()
->orderBy('created_at','asc')
->get()
->map(function ($m) {
    $dm = $m->displayMedia();

    return [

        'id' => $m->id,

        'direction' => $m->direction,

        'content' => $m->content,

        'media_type' => $dm['type'],
        'media_url'  => $dm['url'],
        'filename'   => $dm['filename'],

        'source' => $m->source,

        // delivery status
        'status' => $m->status ?? 'pending',

        'time' => optional($m->created_at)->format('H:i')

    ];

});


/*
|--------------------------------------------------------------------------
| ONLINE DETECTION
|--------------------------------------------------------------------------
| online if last activity < 60 seconds
*/

    $lastIncoming = $conversation->messages()
        ->where('direction', 'incoming')
        ->latest('id')
        ->first();

    $online = $lastIncoming
        && $lastIncoming->created_at
        && $lastIncoming->created_at->gt(now()->subMinutes(3));

    $lastSeen = $lastIncoming && $lastIncoming->created_at
        ? $lastIncoming->created_at->diffForHumans()
        : null;

return response()->json([

    'messages' => $messages,
    'online'   => $online,
    'last_seen' => $lastSeen,
    'conversation_status' => $conversation->status,

]);

}

/*
|--------------------------------------------------------------------------
| CLOSE CONVERSATION
|--------------------------------------------------------------------------
*/
public function bulkSend(Request $request)
{

$request->validate([
'message'=>'required|string|max:5000'
]);

$messageText = $request->message;

$phoneNumberId = config('services.whatsapp.phone_number_id');
$token = config('services.whatsapp.access_token');

$endpoint =
config('services.whatsapp.graph_url').'/'
.config('services.whatsapp.graph_version').'/'
.$phoneNumberId.'/messages';


$conversations = Conversation::where('channel','whatsapp')
->where('is_active',1)
->limit(50)
->get();


foreach($conversations as $conversation){

try{

$response = Http::withToken($token)->post($endpoint,[

"messaging_product"=>"whatsapp",

"to"=>$conversation->phone_number,

"type"=>"text",

"text"=>[
"body"=>str_replace(
'{name}',
$conversation->customer_name ?? '',
$messageText
)
]

]);

if($response->successful()){

Message::create([
'conversation_id'=>$conversation->id,
'direction'=>'outgoing',
'content'=>$messageText,
'status'=>'sent',
'is_read'=>1
]);

}else{

Log::error('Bulk send failed',[
'phone'=>$conversation->phone_number,
'body'=>$response->body()
]);

}

sleep(1);

}catch(\Throwable $e){

Log::error('Bulk exception',[
'phone'=>$conversation->phone_number,
'error'=>$e->getMessage()
]);

}

}

return back()->with('success','Bulk messages sent');

}
public function close(Conversation $conversation)
{

Log::info('Conversation closed',[
'conversation'=>$conversation->id
]);

$conversation->update([
'status'=>'closed'
]);

return back();

}
public function deleteConversation($id)
{
    // Find the conversation
    $conversation = Conversation::findOrFail($id);

    // Delete all messages linked to this conversation
    $conversation->messages()->delete();

    // Delete the conversation itself
    $conversation->delete();

    // Redirect back to the inbox page
    return redirect('/admin/inbox')->with('success', 'Conversation deleted successfully.');
}
}