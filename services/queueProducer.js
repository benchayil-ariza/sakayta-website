// services/queueProducer.js
// This is your MIDDLEWARE/MESSAGING requirement.
// When a ride is booked, instead of assigning a driver immediately (synchronously),
// we drop it into a RabbitMQ queue. A separate worker (queueConsumer.js) picks it
// up and processes it - that's what makes it "asynchronous."

const amqp = require("amqplib");

let channelPromise = null;

async function getChannel() {
  if (!channelPromise) {
    channelPromise = amqp
      .connect(process.env.RABBITMQ_URL || "amqp://localhost")
      .then((conn) => conn.createChannel())
      .then(async (channel) => {
        await channel.assertQueue("ride_requests", { durable: true });
        return channel;
      });
  }
  return channelPromise;
}

async function publishNewRide(ride) {
  try {
    const channel = await getChannel();
    channel.sendToQueue("ride_requests", Buffer.from(JSON.stringify(ride)), {
      persistent: true,
    });
    console.log(`Queued ride ${ride.id} for driver assignment`);
  } catch (err) {
    console.warn("Could not publish to RabbitMQ (is it running?):", err.message);
  }
}

module.exports = { publishNewRide };
