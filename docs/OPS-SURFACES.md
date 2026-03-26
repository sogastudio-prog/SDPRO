SoloDrive Operator Surfaces

Status: LOCKED

## Dispatch Model

Operators apply constraints to assign drivers.

The system does not require pre-defined availability.

Assignment is a constraint resolution problem, not a lookup.

1. Dispatch Board

Module:

130-dispatch-board.php

Purpose:

Real-time overview of active rides.

Displays:

ride status
pickup/dropoff
requested time
driver assignment
quote status
2. Operator Trips

Modules:

140-operator-trips.php
141-operator-trip-actions.php

Purpose:

Operational ride management.

Operators may:

assign drivers
update ride state
cancel rides
monitor progress
3. Driver Telemetry

Module:

142-operator-location.php

Driver browser surfaces provide:

GPS updates
availability status
trip progress
4. Trip Surface

Public page:

/trip/<token>

Displays:

ride status
driver location
ETA
quote decision UI

The trip surface is the single passenger decision interface.

5. Admin Surfaces

Admin modules include:

ride metabox
quote metabox
meta debug tools
tenant management

Admin is used for:

oversight
debugging
manual corrections