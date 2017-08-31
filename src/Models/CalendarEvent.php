<?php

namespace T1k3\LaravelCalendarEvent\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use T1k3\LaravelCalendarEvent\Enums\RecurringFrequenceType;
use T1k3\LaravelCalendarEvent\Exceptions\InvalidMonthException;
use T1k3\LaravelCalendarEvent\Interfaces\PlaceInterface;
use T1k3\LaravelCalendarEvent\Interfaces\UserInterface;

/**
 * Class CalendarEvent
 * @package T1k3\LaravelCalendarEvent\Models
 */
class CalendarEvent extends AbstractModel
{
    use SoftDeletes;

    /**
     * Fillable
     * @var array
     */
    protected $fillable = [
        'start_date'
    ];

    /**
     * Attribute Casting
     * @var array
     */
    protected $casts = [
        'start_date' => 'date'
    ];

    /**
     * TemplateCalendarEvent
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function template()
    {
        return $this->belongsTo(TemplateCalendarEvent::class, 'template_calendar_event_id');
    }

    /**
     * Calendar event (template) data - input is different
     * @param array $attributes
     * @return bool
     */
    public function dataIsDifferent(array $attributes): bool
    {
        if (isset($attributes['start_date'])) {
            if ($this->start_date->format('Y-m-d') !== $attributes['start_date']) {
                return true;
            }
            unset($attributes['start_date']);
        }

        $template = $this->template;
        foreach ($attributes as $key => $value) {
            if ($template->{$key} !== $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $attributes
     * @param UserInterface|null $user
     * @param PlaceInterface|null $place
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function createCalendarEvent(array $attributes, UserInterface $user = null, PlaceInterface $place = null)
    {
        DB::transaction(function () use ($attributes, $user, $place, &$calendarEvent) {
            $templateCalendarEvent = $this->template()->create($attributes);

            if ($user !== null) $templateCalendarEvent->user()->associate($user);
            if ($place !== null) $templateCalendarEvent->place()->associate($place);

            $calendarEvent = $this->make($attributes);
            $calendarEvent->template()->associate($templateCalendarEvent);
            $calendarEvent->save();
        });

        return $calendarEvent;
    }

    /**
     * @param array $attributes
     * @return null|CalendarEvent
     */
    public function editCalendarEvent(array $attributes)
    {
        if ($this->dataIsDifferent($attributes)) {
            $calendarEventNew = $this->createCalendarEvent(
                array_merge(
                    $this->template->toArray(),
                    ['start_date' => $this->start_date],
                    $attributes
                )
            );

            $calendarEventNew->template->parent()->associate($this->template);
            if ($this->template->is_recurring && $calendarEventNew->template->is_recurring) {
                $this->template->update([
                    'end_of_recurring' => $this->start_date
                ]);
            } else {
                $calendarEventNew->template->update([
                    'end_of_recurring' => null
                ]);
            }
            $this->delete();

            return $calendarEventNew;
        }
        return null;
    }

    /**
     * @param \DateTimeInterface $date
     * @return \Illuminate\Support\Collection|static
     */
    public static function showPotentialCalendarEventsOfMonth(\DateTimeInterface $date)
    {
        $endOfRecurring = $date->lastOfMonth();
        $month          = str_pad($endOfRecurring->month, 2, '0', STR_PAD_LEFT);

        $templateCalendarEvents = TemplateCalendarEvent
            ::where(function ($q) use ($month) {
                $q->where('is_recurring', false)
                    ->whereMonth('start_date', $month);
            })
            ->orWhere(function ($q) use ($endOfRecurring) {
                $q->where('is_recurring', true)
                    ->whereNull('end_of_recurring')
                    ->where('start_date', '<=', $endOfRecurring);
            })
            ->orWhere(function ($q) use ($endOfRecurring) {
                $q->where('is_recurring', true)
                    ->where('start_date', '<=', $endOfRecurring)
                    ->whereMonth('end_of_recurring', '<=', $endOfRecurring);
            })
            ->with('events')
            ->get();

        $calendarEvents = collect();
        foreach ($templateCalendarEvents as $templateCalendarEvent) {
            $calendarEvents       = $calendarEvents->merge($templateCalendarEvent->events()->whereMonth('start_date', $month)->get());
            $dateNext             = null;
            $calendarEventTmpLast = $templateCalendarEvent->events()->orderBy('start_date', 'desc')->first();

            if ($calendarEventTmpLast) {
//                TODO Refactor | OCP, Strategy
                switch ($templateCalendarEvent->frequence_type_of_recurring) {
                    case RecurringFrequenceType::DAY:
                        $diff     = $date->firstOfMonth()->diffInDays($calendarEventTmpLast->start_date);
                        $dateNext = $calendarEventTmpLast->start_date->addDays($diff);
                        break;
                    case RecurringFrequenceType::WEEK:
                        $diff     = $date->firstOfMonth()->diffInWeeks($calendarEventTmpLast->start_date);
                        $dateNext = $calendarEventTmpLast->start_date->addWeeks($diff);
                        break;
                    case RecurringFrequenceType::MONTH:
                        $diff     = $date->firstOfMonth()->diffInMonths($calendarEventTmpLast->start_date);
                        $dateNext = $calendarEventTmpLast->start_date->addMonths($diff);
                        break;
                    case RecurringFrequenceType::YEAR:
                        $diff     = $date->firstOfMonth()->diffInYears($calendarEventTmpLast->start_date);
                        $dateNext = $calendarEventTmpLast->start_date->addYears($diff);
                }
            }

            while ($dateNext !== null && $dateNext->month <= (int)$month) {
                $calendarEventNotExists               = (new CalendarEvent())->make(['start_date' => $dateNext]);
                $calendarEventNotExists->is_not_exist = true;
                $calendarEventNotExists->template()->associate($templateCalendarEvent);

                if ($calendarEventNotExists->start_date->month === (int)$month
                    && !$templateCalendarEvent->events()->where('start_date', $dateNext)->first()
                ) {
                    $calendarEvents = $calendarEvents->merge(collect([$calendarEventNotExists]));
                }

                $dateNext = $templateCalendarEvent->getNextCalendarEventStartDate($calendarEventNotExists->start_date);
            }
        }

        return $calendarEvents;
    }
}